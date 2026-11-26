-- Empreintes des tables touchées par les PR #42 à #44 : nombre de lignes,
-- md5 de toutes les colonnes (ordre par id) et md5 « contenu » sans
-- created_at / updated_at. Plus l'empreinte du schéma public.
-- Lecture seule. Usage : psql ... -At -f empreintes.sql
with t(nom) as (values ('brands'),('countries'),('manufacturers'),('vehicle_models'),('trims'),('trim_applications'),
  ('engine_types'),('drivetrains'),('part_categories'),('parts'),('part_fitments'),('oem_numbers'),('oem_number_part'),
  ('oem_number_fitments'),('motorisations'),('owned_vehicles'),('vehicles'),('accessory_fitments'),('vehicle_check_items'),
  ('markets'),('oem_number_relations'),('part_fitment_archives'),('motorisation_generation_assignments'))
select t.nom || '|' || coalesce((xpath('/row/r/text()', query_to_xml(format(
  $q$select count(*) || '|' || coalesce(md5(string_agg(to_jsonb(x)::text, ',' order by x.id)), '-') || '|' ||
     coalesce(md5(string_agg((to_jsonb(x) - 'created_at' - 'updated_at')::text, ',' order by x.id)), '-') as r from %I x$q$, t.nom),
  false, true, '')))[1]::text, 'absente')
from t where to_regclass('public.' || t.nom) is not null
union all
select t.nom || '|absente' from t where to_regclass('public.' || t.nom) is null
union all
select 'schema|' || md5(string_agg(table_name || '.' || column_name || ':' || data_type || ':' || is_nullable || ':' || coalesce(column_default, ''), ',' order by table_name, column_name))
from information_schema.columns where table_schema = 'public'
union all
select 'contraintes|' || md5(string_agg(conrelid::regclass || ':' || conname || ':' || pg_get_constraintdef(oid), ',' order by conrelid::regclass::text, conname))
from pg_constraint where connamespace = 'public'::regnamespace
union all
select 'index|' || md5(string_agg(indexdef, ',' order by indexname)) from pg_indexes where schemaname = 'public'
order by 1;
