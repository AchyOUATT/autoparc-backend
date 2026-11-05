#!/usr/bin/env python3
"""Authorize AutoParc's dedicated Gmail sender without printing credentials.

Only Python's standard library is required. No messages are sent by this helper.
The output must be outside every Git checkout and is never overwritten.
"""
from __future__ import annotations

import argparse
import base64
import csv
import hashlib
from http.server import BaseHTTPRequestHandler, HTTPServer
import io
import json
import os
from pathlib import Path
import re
import secrets
import subprocess
import sys
import time
from urllib import error, parse, request
import webbrowser

REDIRECT_URI = "http://127.0.0.1:8765/oauth2/callback"
AUTH_ENDPOINT = "https://accounts.google.com/o/oauth2/v2/auth"
TOKEN_ENDPOINT = "https://oauth2.googleapis.com/token"
USERINFO_ENDPOINT = "https://openidconnect.googleapis.com/v1/userinfo"
REVOKE_ENDPOINT = "https://oauth2.googleapis.com/revoke"
SEND_SCOPE = "https://www.googleapis.com/auth/gmail.send"
SCOPES = ("openid", "email", SEND_SCOPE)


class AuthorizationError(Exception):
    """An operator-safe message: never include provider responses or secrets."""


def outside_git(path: Path) -> Path:
    resolved = path.expanduser().resolve()
    if any((parent / ".git").exists() for parent in (resolved, *resolved.parents)):
        raise AuthorizationError("Le fichier secret doit rester en dehors de tout dépôt Git.")
    return resolved


def validate_output(path: Path) -> Path:
    resolved = outside_git(path)
    if resolved.exists() or resolved.is_symlink():
        raise AuthorizationError("Le fichier de sortie existe déjà ; choisissez un nouveau chemin privé.")
    if not resolved.parent.is_dir():
        raise AuthorizationError("Créez d’abord le dossier privé de destination.")
    return resolved


def nonempty_secret(value: object) -> bool:
    return isinstance(value, str) and 0 < len(value) <= 16384 and not any(c in value for c in "\r\n\0")


def load_client(path: Path) -> dict[str, str]:
    source = outside_git(path)
    try:
        if source.stat().st_size > 100000:
            raise ValueError()
        document = json.loads(source.read_text(encoding="utf-8-sig"))
        client = document.get("web", {})
        if not isinstance(client, dict):
            raise ValueError()
        if not nonempty_secret(client.get("client_id")) or not client["client_id"].endswith(".apps.googleusercontent.com"):
            raise ValueError()
        if not nonempty_secret(client.get("client_secret")):
            raise ValueError()
        if not isinstance(client.get("redirect_uris"), list) or REDIRECT_URI not in client["redirect_uris"]:
            raise ValueError()
        return {"client_id": client["client_id"], "client_secret": client["client_secret"]}
    except (OSError, ValueError, TypeError, AttributeError):
        raise AuthorizationError("Le JSON doit être celui d’un client OAuth Web dédié, avec la redirection locale indiquée dans la documentation.") from None


def authorization_url(client_id: str, sender: str, state: str, verifier: str) -> str:
    challenge = base64.urlsafe_b64encode(hashlib.sha256(verifier.encode("ascii")).digest()).decode("ascii").rstrip("=")
    return AUTH_ENDPOINT + "?" + parse.urlencode({
        "client_id": client_id,
        "redirect_uri": REDIRECT_URI,
        "response_type": "code",
        "scope": " ".join(SCOPES),
        "access_type": "offline",
        "prompt": "consent",
        "include_granted_scopes": "false",
        "login_hint": sender,
        "state": state,
        "code_challenge": challenge,
        "code_challenge_method": "S256",
    })


def parse_callback(target: str, expected_state: str) -> str:
    try:
        if len(target) > 16384:
            raise ValueError()
        parsed = parse.urlsplit(target)
        pairs = parse.parse_qsl(parsed.query, keep_blank_values=True, max_num_fields=16)
    except ValueError:
        raise AuthorizationError("La réponse locale Google n’est pas valide.") from None
    if parsed.path != "/oauth2/callback" or parsed.scheme or parsed.netloc:
        raise AuthorizationError("La réponse locale Google n’est pas valide.")
    values = dict(pairs)
    returned_state = values.get("state", "")
    if len(values) != len(pairs) or not returned_state.isascii() or not secrets.compare_digest(returned_state, expected_state):
        raise AuthorizationError("La vérification de la demande Google a échoué. Relancez l’autorisation.")
    if "error" in values:
        raise AuthorizationError("L’autorisation Google n’a pas été accordée.")
    code = values.get("code")
    if not nonempty_secret(code):
        raise AuthorizationError("Google n’a pas fourni de code d’autorisation utilisable.")
    return code


def receive_code(server: HTTPServer, state: str, timeout: int) -> str:
    result: dict[str, object] = {}

    class CallbackHandler(BaseHTTPRequestHandler):
        def setup(self) -> None:
            self.request.settimeout(5)
            super().setup()

        def log_message(self, *_args: object) -> None:
            pass  # The request URL contains a short-lived authorization code.

        def do_GET(self) -> None:
            if self.path.partition("?")[0] != "/oauth2/callback":
                self.send_error(404)
                return
            try:
                if self.headers.get("Host") != "127.0.0.1:8765":
                    raise AuthorizationError("La réponse locale Google n’est pas valide.")
                result["code"] = parse_callback(self.path, state)
                status, body = 200, "Autorisation reçue. Vous pouvez fermer cet onglet et revenir à la console AutoParc."
            except AuthorizationError as exc:
                result["error"] = exc
                status, body = 400, "Autorisation non terminée. Revenez à la console AutoParc."
            encoded = body.encode("utf-8")
            self.send_response(status)
            self.send_header("Content-Type", "text/plain; charset=utf-8")
            self.send_header("Content-Length", str(len(encoded)))
            self.send_header("Cache-Control", "no-store")
            self.send_header("Referrer-Policy", "no-referrer")
            self.send_header("Content-Security-Policy", "default-src 'none'; frame-ancestors 'none'")
            self.end_headers()
            self.wfile.write(encoded)

    server.RequestHandlerClass = CallbackHandler
    server.timeout = 1
    deadline = time.monotonic() + timeout
    while not result and time.monotonic() < deadline:
        server.handle_request()
    if "error" in result:
        raise result["error"]
    if "code" not in result:
        raise AuthorizationError("Le délai d’autorisation Google est dépassé. Relancez la commande.")
    return str(result["code"])


class NoRedirect(request.HTTPRedirectHandler):
    def redirect_request(self, *_args: object, **_kwargs: object) -> None:
        return None


def google_json(url: str, *, form: dict[str, str] | None = None, token: str | None = None) -> dict:
    headers = {"Accept": "application/json"}
    data = None
    if form is not None:
        headers["Content-Type"] = "application/x-www-form-urlencoded"
        data = parse.urlencode(form).encode("ascii")
    if token is not None:
        headers["Authorization"] = "Bearer " + token
    try:
        opener = request.build_opener(NoRedirect())
        with opener.open(request.Request(url, headers=headers, data=data), timeout=20) as response:
            content = response.read(1000001)
            if not 200 <= response.status < 300 or len(content) > 1000000:
                raise ValueError()
            parsed = json.loads(content)
            if not isinstance(parsed, dict):
                raise ValueError()
            return parsed
    except (OSError, ValueError, error.URLError):
        raise AuthorizationError("La communication sécurisée avec Google a échoué. Aucun secret n’a été affiché.") from None


def revoke(refresh_token: str) -> None:
    try:
        # The revoke endpoint can return an empty body; no response is printed.
        data = parse.urlencode({"token": refresh_token}).encode("ascii")
        opener = request.build_opener(NoRedirect())
        with opener.open(request.Request(REVOKE_ENDPOINT, data=data, headers={"Content-Type": "application/x-www-form-urlencoded"}), timeout=20):
            pass
    except (OSError, error.URLError):
        pass


def exchange(client: dict[str, str], sender: str, code: str, verifier: str) -> dict[str, str]:
    response = google_json(TOKEN_ENDPOINT, form={
        "client_id": client["client_id"], "client_secret": client["client_secret"],
        "code": code, "code_verifier": verifier,
        "grant_type": "authorization_code", "redirect_uri": REDIRECT_URI,
    })
    refresh = response.get("refresh_token")
    try:
        expires = response.get("expires_in")
        token_type = response.get("token_type")
        if (not isinstance(token_type, str) or token_type.lower() != "bearer" or not nonempty_secret(response.get("access_token"))
                or not nonempty_secret(refresh) or not isinstance(expires, int) or isinstance(expires, bool) or expires <= 0):
            raise AuthorizationError("Google n’a pas fourni d’autorisation renouvelable complète. Vérifiez le client OAuth dédié puis recommencez.")
        scope_value = response.get("scope", "")
        if not isinstance(scope_value, str):
            raise AuthorizationError("Les permissions Google ne sont pas valides.")
        granted = set(scope_value.split())
        email_scopes = {"email", "https://www.googleapis.com/auth/userinfo.email"}
        allowed = {"openid", SEND_SCOPE, *email_scopes}
        if SEND_SCOPE not in granted or "openid" not in granted or not granted.intersection(email_scopes) or granted - allowed:
            raise AuthorizationError("Accordez uniquement l’envoi Gmail et l’identification de l’adresse avec le client OAuth dédié.")
        identity = google_json(USERINFO_ENDPOINT, token=response["access_token"])
        if (identity.get("email_verified") is not True or not isinstance(identity.get("email"), str)
                or identity["email"].casefold() != sender.casefold() or not nonempty_secret(identity.get("sub"))):
            raise AuthorizationError("Le compte Google autorisé ne correspond pas à l’adresse expéditrice attendue.")
        return {"GMAIL_CLIENT_ID": client["client_id"], "GMAIL_CLIENT_SECRET": client["client_secret"],
                "GMAIL_REFRESH_TOKEN": refresh, "MAIL_FROM_ADDRESS": sender, "MAIL_FROM_NAME": "AutoParc", "MAIL_MAILER": "gmail_api"}
    except AuthorizationError:
        if nonempty_secret(refresh):
            revoke(refresh)
        raise


def env_quote(value: str) -> str:
    if not nonempty_secret(value):
        raise AuthorizationError("Une valeur secrète ne peut pas être enregistrée de façon sûre.")
    return '"' + value.replace("\\", "\\\\").replace('"', '\\"').replace("$", "\\$") + '"'


def restrict_windows(path: Path) -> None:
    try:
        result = subprocess.run(["whoami", "/user", "/fo", "csv", "/nh"], capture_output=True, text=True, check=True)
        sid = next(csv.reader(io.StringIO(result.stdout)))[1]
        if not re.fullmatch(r"S-1-(?:[0-9]+-)*[0-9]+", sid):
            raise ValueError()
        subprocess.run(["icacls", str(path), "/inheritance:r", "/grant:r", f"*{sid}:(F)"], capture_output=True, check=True)
    except (OSError, ValueError, IndexError, StopIteration, subprocess.SubprocessError):
        raise AuthorizationError("Impossible de protéger le fichier secret avec les droits Windows du compte actuel.") from None


def write_private_env(path: Path, values: dict[str, str]) -> None:
    destination = validate_output(path)
    payload = "".join(f"{key}={env_quote(value)}\n" for key, value in values.items())
    fd = None
    created = False
    try:
        fd = os.open(destination, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        created = True
        if os.name == "nt":
            restrict_windows(destination)
        else:
            os.fchmod(fd, 0o600)
        with os.fdopen(fd, "w", encoding="utf-8", newline="\n") as output:
            fd = None
            output.write(payload)
    except (OSError, AuthorizationError):
        if fd is not None:
            os.close(fd)
        if created:
            destination.unlink(missing_ok=True)
        raise AuthorizationError("Le fichier privé n’a pas pu être créé ; aucun secret n’a été affiché.") from None


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Autoriser l’expéditeur Gmail AutoParc, sans afficher de secret ni envoyer d’email.")
    parser.add_argument("--client-json", type=Path, required=True)
    parser.add_argument("--sender", required=True)
    parser.add_argument("--output-env", type=Path, required=True)
    parser.add_argument("--no-browser", action="store_true")
    args = parser.parse_args(argv)
    credentials = None
    try:
        if not re.fullmatch(r"[^\s@<>]+@[^\s@<>]+\.[^\s@<>]+", args.sender):
            raise AuthorizationError("Indiquez une adresse expéditrice valide.")
        destination = validate_output(args.output_env)
        client = load_client(args.client_json)
        state, verifier = secrets.token_urlsafe(32), secrets.token_urlsafe(64)
        url = authorization_url(client["client_id"], args.sender, state, verifier)
        try:
            server = HTTPServer(("127.0.0.1", 8765), BaseHTTPRequestHandler)
        except OSError:
            raise AuthorizationError("Le port local 8765 est indisponible. Fermez l’autre application et recommencez.") from None
        with server:
            print("Ouvrez cette adresse Google et autorisez uniquement l’envoi avec le compte expéditeur :")
            print(url)
            if not args.no_browser:
                webbrowser.open(url)
            code = receive_code(server, state, timeout=600)
        credentials = exchange(client, args.sender, code, verifier)
        write_private_env(destination, credentials)
        print("Autorisation vérifiée. Le fichier privé a été créé. Importez ses variables dans Render sans partager son contenu.")
        return 0
    except AuthorizationError as exc:
        if credentials is not None:
            revoke(credentials["GMAIL_REFRESH_TOKEN"])
        print("Autorisation non terminée : " + str(exc), file=sys.stderr)
        return 1
    except KeyboardInterrupt:
        if credentials is not None:
            revoke(credentials["GMAIL_REFRESH_TOKEN"])
        print("Autorisation interrompue. Aucun secret n’a été affiché.", file=sys.stderr)
        return 1
    except Exception:
        if credentials is not None:
            revoke(credentials["GMAIL_REFRESH_TOKEN"])
        print("Autorisation non terminée. Vérifiez la configuration locale puis recommencez ; aucun secret n’a été affiché.", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
