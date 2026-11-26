-- Vérifie TOUTES les clés étrangères de la base : lignes orphelines par contrainte.
do $$ declare r record; n bigint; total int := 0; begin
for r in
  select con.conname, cl.relname tbl, a.attname col, fcl.relname ftbl, fa.attname fcol
  from pg_constraint con
  join pg_class cl on cl.oid = con.conrelid join pg_class fcl on fcl.oid = con.confrelid
  join pg_attribute a on a.attrelid = con.conrelid and a.attnum = con.conkey[1]
  join pg_attribute fa on fa.attrelid = con.confrelid and fa.attnum = con.confkey[1]
  where con.contype = 'f' and array_length(con.conkey,1) = 1
loop
  execute format('select count(*) from %I t where t.%I is not null and not exists (select 1 from %I f where f.%I = t.%I)', r.tbl, r.col, r.ftbl, r.fcol, r.col) into n;
  if n > 0 then raise notice 'ORPHELINS % : %.% -> %.% : %', r.conname, r.tbl, r.col, r.ftbl, r.fcol, n; total := total + 1; end if;
end loop;
raise notice 'contraintes FK en défaut : %', total;
end $$;
