#!/usr/bin/env python3
"""Charge un export LECTURE SEULE de la production (voir export-*.sql) dans une
base PostgreSQL locale dont le schéma vient des migrations du code voulu
(main avant fusion, commit déployé après).

    charger-copie.py <base> <catalogue.json> <vehicules.json> [--port 55418] [--hote /tmp]

- Vide toutes les tables, puis insère chaque table de l'export, clés
  étrangères suspendues pendant le chargement (session_replication_role).
- Aucune donnée personnelle : l'export ne contient ni VIN, ni plaque, ni nom,
  ni e-mail, ni téléphone. Les comptes référencés (users, partner_accounts,
  partners) sont recréés en fiches neutres « Utilisateur N ».
- Recale les séquences ; lancer ensuite controle_fk.sql (0 orphelin attendu).
"""
import argparse, json, subprocess

ap = argparse.ArgumentParser()
ap.add_argument('base'); ap.add_argument('catalogue'); ap.add_argument('vehicules')
ap.add_argument('--port', default='55418'); ap.add_argument('--hote', default='/tmp'); ap.add_argument('--utilisateur', default='postgres')
o = ap.parse_args()

def ouvrir(chemin):
    # Accepte le fichier brut de query_render_postgres ([{"dump": {...}}]) ou le JSON seul.
    t = open(chemin).read()
    if '[{"dump"' in t:
        i = t.index('[{"dump"'); j = t.rindex(']') + 1
        return json.loads(t[i:j])[0]['dump']
    return json.loads(t)

data = {**ouvrir(o.catalogue), **ouvrir(o.vehicules)}
refs = data.pop('refs', {'users': [], 'partner_accounts': [], 'partners': []})
stubs = {
    'users': [{'id': i, 'name': f'Utilisateur {i}', 'password': 'x', 'role': 'admin'} for i in refs['users']]
           + [{'id': 900000 + i, 'name': f'Compte partenaire {i}', 'password': 'x', 'role': 'partner'} for i in refs['partner_accounts']],
    'partner_accounts': [{'id': i, 'user_id': 900000 + i, 'contact_name': f'Partenaire {i}', 'phone': f'0000{i}'} for i in refs['partner_accounts']],
    'partners': [{'id': i, 'company_name': f'Partenaire {i}', 'phone': f'0000{i}'} for i in refs['partners']],
}
sql = ["\\set ON_ERROR_STOP on", "set client_min_messages = warning;", "begin;", "set session_replication_role = replica;",
       "do $$ declare r record; begin for r in select tablename from pg_tables where schemaname='public' loop "
       "execute format('truncate table %I restart identity cascade', r.tablename); end loop; end $$;"]

def charge(t, rows):
    if not rows:
        return
    j = json.dumps(rows)
    assert '$J$' not in j
    cols = sorted({k for r in rows for k in r})
    cl = ', '.join(f'"{c}"' for c in cols)
    sql.append(f"insert into {t} ({cl}) select {cl} from json_populate_recordset(null::{t}, $J${j}$J$);")

for t, rows in stubs.items():
    charge(t, rows)
for t, rows in data.items():
    charge(t, rows)
sql += ["set session_replication_role = origin;",
        """do $$ declare r record; begin
for r in select c.table_name, c.column_name from information_schema.columns c
  where c.table_schema='public' and c.column_default like 'nextval%' loop
  execute format('select setval(pg_get_serial_sequence(%L,%L), greatest(coalesce((select max(%I) from %I),0),1))',
    r.table_name, r.column_name, r.column_name, r.table_name);
end loop; end $$;""", "commit;"]
open('/tmp/charge-copie.sql', 'w').write("\n".join(sql))
subprocess.run(['psql', '-q', '-h', o.hote, '-p', o.port, '-U', o.utilisateur, o.base, '-f', '/tmp/charge-copie.sql'], check=True)
print('chargé :', ', '.join(f'{t}={len(r or [])}' for t, r in data.items()))
