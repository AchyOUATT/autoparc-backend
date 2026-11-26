#!/bin/bash
# Déploiements successifs main → #42 → #40 → #41 → #43 → #44 sur une copie type
# prod (PostgreSQL 18), puis retour arrière complet, avec empreintes à chaque étape.
#
# Prérequis :
#  - PostgreSQL 18 local (ici port 55418, socket /tmp, utilisateur postgres) ;
#  - une base modèle REF déjà migrée avec main puis chargée par charger-copie.py ;
#  - un clone de travail (DEPOT) avec vendor/ et .env ; on y fait des checkout détachés.
# Usage : BASE=chaine REF=prodlike_ref DEPOT=/chemin/clone SORTIE=/tmp/verif scripts/verification-deploiement/repetition-chaine.sh
set -u
B=${BASE:-chaine}; V=${SORTIE:-/tmp/verif}/$B; mkdir -p $V
D=$(cd "$(dirname "$0")" && pwd)
P="psql -h ${PGHOST:-/tmp} -p ${PGPORT:-55418} -U ${PGUSER:-postgres}"
export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=${PGPORT:-55418} DB_DATABASE=$B DB_USERNAME=${PGUSER:-postgres} DB_PASSWORD= DB_URL=
cd "${DEPOT:?DEPOT=clone de travail}"
$P -qc "drop database if exists $B" -c "create database $B template ${REF:-prodlike_ref}"
code() { git checkout -q --detach "$1" && composer dump-autoload -q && php artisan config:clear -q; }
seeders() { for s in VehicleModelsSeeder TrimsSeeder KiaCatalogueSeeder TwoWheelerCatalogSeeder VehicleCheckItemsSeeder; do php artisan db:seed --class=$s --force >/dev/null 2>&1 || echo "  seeder en échec (toléré) : $s"; done; }
empreinte() { $P $B -At -f $D/empreintes.sql > $V/empreinte-$1.txt; $P $B -At -c "select to_jsonb(x) from part_fitments x order by id" > $V/fitments-$1.jsonl; $P $B -At -c "select to_jsonb(x) - 'updated_at' from vehicle_models x order by id" > $V/modeles-$1.jsonl; $P $B -At -c "select to_jsonb(x) from motorisations x order by id" > $V/motorisations-$1.jsonl; }
$P $B -At -f $D/empreintes.sql > $V/empreinte-copie-brute.txt
echo "=== main : démarrage tel quel"; code origin/main; php artisan migrate --force | grep -E "2026|Nothing"; seeders
empreinte avant; php $D/instantane-verdicts.php $V/verdicts-avant.json 2>/dev/null
ETATS="pr42:origin/feat/moteur-compatibilite-chassis-marche pr40:origin/feat/compatibilites-filtre-90915-yzzd1 pr41:origin/feat/modeles-anciens-toyota-lexus-yzzd1 pr43:origin/feat/referentiel-sienna pr44:origin/fix/sienna-retour-arriere-identifiants"
for e in $ETATS; do n=${e%%:*}; r=${e#*:}
  echo "=== + $n ($(git rev-parse --short $r))"; code $r
  php artisan migrate --force 2>&1 | grep -E "^ *2026_11_2[5-9]|ERROR|Exception|Nothing" | sed -E 's/ \.+ / /; s/^ +//' | cut -c1-200
  seeders; empreinte $n; php $D/instantane-verdicts.php $V/verdicts-$n.json $V/verdicts-avant.json 2>/dev/null
  $P $B -f $D/controle_fk.sql 2>&1 | grep -E "ORPHELINS|en défaut"
done
for e in pr44:origin/fix/sienna-retour-arriere-identifiants pr43:origin/feat/referentiel-sienna pr41:origin/feat/modeles-anciens-toyota-lexus-yzzd1 pr40:origin/feat/compatibilites-filtre-90915-yzzd1 pr42:origin/feat/moteur-compatibilite-chassis-marche; do n=${e%%:*}; r=${e#*:}
  echo "=== retour arrière de $n (code $(git rev-parse --short $r))"; code $r
  php artisan migrate:rollback --force 2>&1 | grep -E "2026|ERROR|Exception|Nothing" | sed -E 's/ \.+ / /; s/^ +//' | cut -c1-200
  empreinte retour-$n
done
echo "=== redéploiement de main"; code origin/main; php artisan migrate --force | grep -E "2026|Nothing"; seeders
empreinte apres-retour; php $D/instantane-verdicts.php $V/verdicts-apres-retour.json $V/verdicts-pr44.json 2>/dev/null
$P $B -f $D/controle_fk.sql 2>&1 | grep -E "ORPHELINS|en défaut"
echo "=== différences avant / après retour"; diff <(sort $V/empreinte-avant.txt) <(sort $V/empreinte-apres-retour.txt) && echo IDENTIQUE
echo "=== copie brute / avant (effet des seeders de main)"; diff <(sort $V/empreinte-copie-brute.txt) <(sort $V/empreinte-avant.txt) && echo IDENTIQUE
