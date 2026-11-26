-- Export LECTURE SEULE (SELECT) des tables de référentiel et de catalogue,
-- sans donnée personnelle. À exécuter avec query_render_postgres (résultat
-- écrit dans un fichier), puis charger avec charger-copie.py.
-- APRÈS le déploiement de #42 à #44, décommenter les 4 tables nouvelles.
select json_build_object(
  'countries', (select json_agg(t) from countries t), 'brands', (select json_agg(t) from brands t),
  'manufacturers', (select json_agg(t) from manufacturers t), 'vehicle_models', (select json_agg(t) from vehicle_models t),
  'engine_types', (select json_agg(t) from engine_types t), 'drivetrains', (select json_agg(t) from drivetrains t),
  'colors', (select json_agg(t) from colors t), 'trims', (select json_agg(t) from trims t),
  'trim_applications', (select json_agg(t) from trim_applications t), 'part_categories', (select json_agg(t) from part_categories t),
  'parts', (select json_agg(t) from parts t), 'part_fitments', (select json_agg(t) from part_fitments t),
  'oem_numbers', (select json_agg(t) from oem_numbers t), 'oem_number_part', (select json_agg(t) from oem_number_part t),
  'oem_number_fitments', (select json_agg(t) from oem_number_fitments t), 'accessories', (select json_agg(t) from accessories t),
  'accessory_fitments', (select json_agg(t) from accessory_fitments t), 'features', (select json_agg(t) from features t),
  'vehicle_check_items', (select json_agg(t) from vehicle_check_items t), 'migrations', (select json_agg(t) from migrations t)
  -- , 'markets', (select json_agg(t) from markets t), 'oem_number_relations', (select json_agg(t) from oem_number_relations t)
  -- , 'part_fitment_archives', (select json_agg(t) from part_fitment_archives t)
  -- , 'motorisation_generation_assignments', (select json_agg(t) from motorisation_generation_assignments t)
) as dump;
