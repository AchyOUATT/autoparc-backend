-- Export LECTURE SEULE des véhicules réels réduit aux critères de
-- compatibilité : ni VIN, ni plaque, ni surnom, ni description, ni prix.
-- APRÈS #42, ajouter circulation_country aux deux listes de colonnes.
select json_build_object(
 'vehicles', (select json_agg(t) from (select id, reference, registration_status, brand_id, vehicle_model_id, trim_id, engine_type_id,
    drivetrain_id, color_id, manufacturing_year, transmission, gear_count, engine_displacement_cc, power_hp, power_kw, torque_nm, cylinders,
    seats, doors, engine_code, condition, status, availability, currency, price_negotiable, site, published_at, created_at, updated_at,
    deleted_at, location_id, vehicle_type, body_style, partner_id, deal_type, owner_partner_account_id, partner_published_at,
    fault_declaration_state, heavy_configuration, asset_kind, equipment_type, equipment_details, two_wheeler_configuration from vehicles) t),
 'owned_vehicles', (select json_agg(t) from (select id, user_id, brand_id, vehicle_model_id, trim_id, engine_type_id, drivetrain_id, color_id,
    manufacturing_year, mileage_km, created_at, updated_at, deleted_at, engine_code, motorisation_id, model_libre, model_year,
    specification_market, engine_displacement_cc, engine_displacement_l, cylinders, engine_layout, power_hp, vehicle_type,
    two_wheeler_configuration from owned_vehicles) t),
 -- Motorisations : seulement celles que #44 touche (Sienna) et celles des véhicules du garage.
 'motorisations', (select json_agg(t) from (select m.* from motorisations m left join vehicle_models vm on vm.id = m.vehicle_model_id
    where vm.slug like 'sienna%' or m.id in (select motorisation_id from owned_vehicles where motorisation_id is not null)
    or (m.vehicle_model_id is null and m.make_raw ilike 'toyota' and m.model_raw ilike '%sienna%')) t),
 'feature_vehicle', (select json_agg(t) from feature_vehicle t),
 'refs', json_build_object(
    'users', (select json_agg(distinct x) from (select user_id x from owned_vehicles union select created_by from vehicles
              union select user_id from partner_accounts) s where x is not null),
    'partner_accounts', (select json_agg(distinct x) from (select owner_partner_account_id x from parts
              union select owner_partner_account_id from vehicles union select owner_partner_account_id from accessories) s where x is not null),
    'partners', (select json_agg(distinct partner_id) from vehicles where partner_id is not null))
) as dump;
