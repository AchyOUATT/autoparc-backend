"""Offline tests: no OAuth requests, email sends or real credentials."""
import base64
import contextlib
import csv
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import stat
import subprocess
import tempfile
import unittest
from unittest.mock import patch
from unittest.mock import MagicMock
from urllib import parse

MODULE = Path(__file__).resolve().parents[1] / "authorize_gmail.py"
SPEC = importlib.util.spec_from_file_location("authorize_gmail", MODULE)
oauth = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(oauth)


class AuthorizationTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name)
        self.client = {"client_id": "mailer-test.apps.googleusercontent.com", "client_secret": "test-client-secret"}
        self.sender = "operator@example.test"
        self.token = {"access_token": "test-access-token", "refresh_token": "test-refresh-token", "expires_in": 3600,
                      "token_type": "Bearer", "scope": "openid email " + oauth.SEND_SCOPE}
        self.identity = {"sub": "test-user", "email": self.sender.upper(), "email_verified": True}

    def test_authorization_requests_offline_send_only_pkce_and_distinct_state(self):
        verifier = "v" * 86
        url = oauth.authorization_url(self.client["client_id"], self.sender, "random-state", verifier)
        parsed = parse.urlsplit(url)
        query = parse.parse_qs(parsed.query)
        self.assertEqual("https://accounts.google.com/o/oauth2/v2/auth", parsed.scheme + "://" + parsed.netloc + parsed.path)
        self.assertEqual(set(oauth.SCOPES), set(query["scope"][0].split()))
        self.assertEqual(["offline"], query["access_type"])
        self.assertEqual(["consent"], query["prompt"])
        self.assertEqual(["false"], query["include_granted_scopes"])
        self.assertEqual(["S256"], query["code_challenge_method"])
        expected = base64.urlsafe_b64encode(hashlib.sha256(verifier.encode()).digest()).decode().rstrip("=")
        self.assertEqual([expected], query["code_challenge"])
        self.assertNotIn(verifier, url)
        self.assertNotIn("client_secret", query)

    def test_callback_state_error_duplicates_and_path_never_yield_a_code(self):
        for target in ["/oauth2/callback?code=private-code&state=wrong", "/oauth2/callback?code=private-code",
                       "/oauth2/callback?code=private-code&state=%C3%A9trange",
                       "/oauth2/callback?code=private-code&state=good&state=good",
                       "/oauth2/callback?error=private-error&state=good", "/wrong?code=private-code&state=good",
                       "/oauth2/callback?state=good", "https://evil.invalid/oauth2/callback?code=private-code&state=good"]:
            with self.subTest(target=target), self.assertRaises(oauth.AuthorizationError) as caught:
                oauth.parse_callback(target, "good")
            self.assertNotIn("private-code", str(caught.exception))
            self.assertNotIn("private-error", str(caught.exception))
        self.assertEqual("private-code", oauth.parse_callback("/oauth2/callback?state=good&code=private-code", "good"))

    def test_web_client_must_register_exact_local_redirect_and_live_outside_git(self):
        path = self.root / "client.json"
        path.write_text(json.dumps({"web": dict(self.client, redirect_uris=[oauth.REDIRECT_URI])}))
        self.assertEqual(self.client, oauth.load_client(path))
        path.write_text(json.dumps({"web": dict(self.client, redirect_uris=["http://localhost:8765/oauth2/callback"])}))
        with self.assertRaises(oauth.AuthorizationError):
            oauth.load_client(path)
        path.write_text(json.dumps({"installed": dict(self.client, redirect_uris=[oauth.REDIRECT_URI])}))
        with self.assertRaises(oauth.AuthorizationError):
            oauth.load_client(path)
        (self.root / ".git").mkdir()
        with self.assertRaises(oauth.AuthorizationError):
            oauth.load_client(path)

    def test_exchange_uses_pkce_and_verified_selected_identity(self):
        with patch.object(oauth, "google_json", side_effect=[self.token, self.identity]) as google, patch.object(oauth, "revoke") as revoke:
            values = oauth.exchange(self.client, self.sender, "test-code", "test-verifier")
        self.assertEqual("test-refresh-token", values["GMAIL_REFRESH_TOKEN"])
        self.assertEqual("gmail_api", values["MAIL_MAILER"])
        self.assertEqual(self.sender, values["MAIL_FROM_ADDRESS"])
        self.assertEqual("test-verifier", google.call_args_list[0].kwargs["form"]["code_verifier"])
        self.assertEqual(oauth.TOKEN_ENDPOINT, google.call_args_list[0].args[0])
        self.assertEqual(oauth.USERINFO_ENDPOINT, google.call_args_list[1].args[0])
        self.assertEqual({"token": "test-access-token"}, google.call_args_list[1].kwargs)
        revoke.assert_not_called()

    def test_wrong_unverified_or_absent_sender_revokes_grant_without_output(self):
        for identity in [dict(self.identity, email="someone@example.test"), dict(self.identity, email_verified=False),
                         {"email": self.sender, "email_verified": True}]:
            with self.subTest(identity=identity), patch.object(oauth, "google_json", side_effect=[self.token, identity]), patch.object(oauth, "revoke") as revoke:
                with self.assertRaises(oauth.AuthorizationError) as caught:
                    oauth.exchange(self.client, self.sender, "code", "verifier")
                self.assertNotIn("test-refresh-token", str(caught.exception))
                revoke.assert_called_once_with("test-refresh-token")
        self.assertEqual([], list(self.root.iterdir()))

    def test_missing_send_scope_or_excessive_mailbox_rights_rejected(self):
        for scopes in ["openid email", "openid " + oauth.SEND_SCOPE,
                       "email " + oauth.SEND_SCOPE, "openid email " + oauth.SEND_SCOPE + " https://www.googleapis.com/auth/gmail.readonly"]:
            with self.subTest(scopes=scopes), patch.object(oauth, "google_json", return_value=dict(self.token, scope=scopes)) as google, patch.object(oauth, "revoke") as revoke:
                with self.assertRaises(oauth.AuthorizationError):
                    oauth.exchange(self.client, self.sender, "code", "verifier")
                self.assertEqual(1, google.call_count)
                revoke.assert_called_once_with("test-refresh-token")

    def test_incomplete_or_malformed_token_response_never_reaches_identity(self):
        for changes in [{"token_type": 5}, {"token_type": "Other"}, {"access_token": ""}, {"refresh_token": ""},
                        {"expires_in": True}, {"expires_in": 0}, {"expires_in": "3600"}, {"scope": None}]:
            with self.subTest(changes=changes), patch.object(oauth, "google_json", return_value=dict(self.token, **changes)) as google, patch.object(oauth, "revoke"):
                with self.assertRaises(oauth.AuthorizationError) as caught:
                    oauth.exchange(self.client, self.sender, "code", "verifier")
                self.assertEqual(1, google.call_count)
                self.assertNotIn("test-client-secret", str(caught.exception))
                self.assertNotIn("test-refresh-token", str(caught.exception))

    def test_private_env_output_is_exclusive_and_quoted_without_secret_stdout(self):
        destination = self.root / "gmail.env"
        values = {"GMAIL_CLIENT_SECRET": 'a$\\"z', "GMAIL_REFRESH_TOKEN": "test-refresh-token"}
        stdout, stderr = io.StringIO(), io.StringIO()
        with contextlib.redirect_stdout(stdout), contextlib.redirect_stderr(stderr):
            oauth.write_private_env(destination, values)
        self.assertEqual("", stdout.getvalue() + stderr.getvalue())
        self.assertEqual('GMAIL_CLIENT_SECRET="a\\$\\\\\\"z"\nGMAIL_REFRESH_TOKEN="test-refresh-token"\n', destination.read_text())
        with self.assertRaises(oauth.AuthorizationError):
            oauth.write_private_env(destination, values)
        if os.name != "nt":
            self.assertEqual(0o600, stat.S_IMODE(destination.stat().st_mode))

    def test_repository_even_gitignored_is_not_an_output_destination(self):
        (self.root / ".git").write_text("gitdir: elsewhere")
        nested = self.root / "private"
        nested.mkdir()
        with self.assertRaises(oauth.AuthorizationError):
            oauth.write_private_env(nested / "gmail.env", {"GMAIL_REFRESH_TOKEN": "token"})
        self.assertFalse((nested / "gmail.env").exists())

    @unittest.skipUnless(os.name == "nt", "Windows file DACL only")
    def test_windows_acl_grants_only_the_current_user_before_private_write(self):
        destination = self.root / "gmail.env"
        oauth.write_private_env(destination, {"GMAIL_REFRESH_TOKEN": "dummy-offline-test"})
        saved_acl = self.root / "acl.txt"
        subprocess.run(["icacls", str(destination), "/save", str(saved_acl), "/q"], capture_output=True, check=True)
        sid_result = subprocess.run(["whoami", "/user", "/fo", "csv", "/nh"], capture_output=True, text=True, check=True)
        sid = next(csv.reader(io.StringIO(sid_result.stdout)))[1]
        raw_acl = saved_acl.read_bytes()
        # icacls /save writes UTF-16LE without a BOM on some Windows builds.
        encoding = "utf-16" if raw_acl.startswith(b"\xff\xfe") else ("utf-16-le" if b"\x00" in raw_acl else "utf-8")
        acl = raw_acl.decode(encoding)
        self.assertEqual(1, acl.count("(A;"))
        self.assertIn(";;;" + sid + ")", acl)
        self.assertNotIn("(A;ID", acl)

    def test_loopback_callback_checks_host_and_bounds_slow_connections(self):
        for host in ["127.0.0.1:8765", "evil.invalid:8765"]:
            with self.subTest(host=host):
                captured = {}

                class FakeServer:
                    def handle_request(server):
                        handler = server.RequestHandlerClass.__new__(server.RequestHandlerClass)
                        handler.request = MagicMock()
                        with patch.object(oauth.BaseHTTPRequestHandler, "setup"):
                            handler.setup()
                        handler.request.settimeout.assert_called_once_with(5)
                        handler.headers = {"Host": host}
                        handler.path = "/oauth2/callback?state=good&code=private-code"
                        handler.wfile = io.BytesIO()
                        handler.send_response = MagicMock()
                        handler.send_header = MagicMock()
                        handler.end_headers = MagicMock()
                        handler.do_GET()
                        captured["body"] = handler.wfile.getvalue()

                if host.startswith("127.0.0.1"):
                    self.assertEqual("private-code", oauth.receive_code(FakeServer(), "good", 10))
                else:
                    with self.assertRaises(oauth.AuthorizationError):
                        oauth.receive_code(FakeServer(), "good", 10)
                self.assertNotIn(b"private-code", captured["body"])

    def test_symbolic_link_output_is_never_followed_or_overwritten(self):
        target = self.root / "unchanged.txt"
        target.write_text("original")
        linked = self.root / "linked.env"
        try:
            linked.symlink_to(target)
        except OSError:
            self.skipTest("Symbolic link creation is unavailable on this host")
        with self.assertRaises(oauth.AuthorizationError):
            oauth.write_private_env(linked, {"GMAIL_REFRESH_TOKEN": "dummy-token"})
        self.assertEqual("original", target.read_text())

    def test_failed_acl_is_removed_before_any_secret_is_written(self):
        destination = self.root / "gmail.env"
        with patch.object(oauth.os, "name", "nt"), patch.object(oauth, "restrict_windows", side_effect=oauth.AuthorizationError("test")):
            with self.assertRaises(oauth.AuthorizationError):
                oauth.write_private_env(destination, {"GMAIL_REFRESH_TOKEN": "test-refresh-token"})
        self.assertFalse(destination.exists())

    def test_http_provider_failure_message_does_not_expose_response(self):
        private = oauth.error.HTTPError(oauth.TOKEN_ENDPOINT, 400, "private-client-secret", {}, io.BytesIO(b'{"error":"private-refresh-token"}'))
        with patch.object(oauth.request, "build_opener") as opener:
            opener.return_value.open.side_effect = private
            with self.assertRaises(oauth.AuthorizationError) as caught:
                oauth.google_json(oauth.TOKEN_ENDPOINT, form={"client_secret": "private-client-secret"})
        self.assertNotIn("private-client-secret", str(caught.exception))
        self.assertNotIn("private-refresh-token", str(caught.exception))

    def test_no_redirect_of_client_secret_to_another_server(self):
        self.assertIsNone(oauth.NoRedirect().redirect_request(None, None, 302, None, None, "https://evil.invalid"))

    def test_main_cancellation_keeps_private_values_out_of_console_and_disk(self):
        path = self.root / "client.json"
        path.write_text(json.dumps({"web": dict(self.client, redirect_uris=[oauth.REDIRECT_URI])}))
        destination = self.root / "gmail.env"
        output, errors = io.StringIO(), io.StringIO()
        with patch.object(oauth, "HTTPServer"), patch.object(oauth, "receive_code", side_effect=oauth.AuthorizationError("Autorisation refusée.")), patch.object(oauth, "google_json") as google, contextlib.redirect_stdout(output), contextlib.redirect_stderr(errors):
            result = oauth.main(["--client-json", str(path), "--sender", self.sender, "--output-env", str(destination), "--no-browser"])
        self.assertEqual(1, result)
        google.assert_not_called()
        self.assertFalse(destination.exists())
        self.assertNotIn("test-client-secret", output.getvalue() + errors.getvalue())
        self.assertNotIn("test-refresh-token", output.getvalue() + errors.getvalue())


if __name__ == "__main__":
    unittest.main()
