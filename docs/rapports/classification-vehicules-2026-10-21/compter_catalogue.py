#!/usr/bin/env python3
"""Audit statique, sans execution PHP ni acces a la base de donnees.

Lit les tableaux litteraux des seeders de modeles et de finitions. Les
commentaires ne sont pas des donnees. Ne modifie aucun fichier du depot.

Usage: python compter_catalogue.py [--repo /workspace/autoparc-backend]
                                  [--output <directory>]
La fonction slug reproduit les operations de Laravel Str::slug pour les
caracteres effectivement presents dans ce catalogue (lettres latines,
chiffres, espaces et ponctuation). Ce n'est pas une implementation generale
de la translitteration Laravel pour toutes les langues.
"""

import argparse
import collections
import csv
import json
from pathlib import Path
import re
import subprocess
import unicodedata


TOKEN = re.compile(r"\s*(?:'((?:\\.|[^'\\])*)'|(null|\d+|\$[A-Za-z_][A-Za-z0-9_]*))\s*")


def literal_rows(path):
    rows = []
    for number, line in enumerate(path.read_text().splitlines(), 1):
        if not re.match(r"\s*\['", line):
            continue
        content = line[line.index('[') + 1:]
        values = []
        while True:
            match = TOKEN.match(content)
            if match is None:
                raise ValueError(f'{path}:{number}: literal non reconnu {content!r}')
            if match.group(1) is not None:
                value = match.group(1).replace("\\'", "'").replace('\\\\', '\\')
            else:
                raw = match.group(2)
                value = None if raw == 'null' else int(raw) if raw.isdigit() else raw
            values.append(value)
            content = content[match.end():]
            if content.startswith(']'):
                break
            if not content.startswith(','):
                raise ValueError(f'{path}:{number}: separateur inattendu {content!r}')
            content = content[1:]
        rows.append((number, values))
    return rows


def slug(value):
    value = unicodedata.normalize('NFKD', value).encode('ascii', 'strict' if value.isascii() else 'ignore').decode().lower()
    value = value.replace('_', '-').replace('@', ' at ')
    value = re.sub(r'[^a-z0-9\s-]', '', value)
    return re.sub(r'[-\s]+', '-', value).strip('-')


def write_csv(path, fields, rows):
    with path.open('w', newline='', encoding='utf-8-sig') as handle:
        writer = csv.DictWriter(handle, fieldnames=fields)
        writer.writeheader()
        writer.writerows(rows)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--repo', type=Path, default=Path('/workspace/autoparc-backend'))
    parser.add_argument('--output', type=Path, default=Path(__file__).resolve().parent)
    args = parser.parse_args()
    args.output.mkdir(parents=True, exist_ok=True)
    model_path = args.repo / 'database/seeders/VehicleModelsSeeder.php'
    trim_path = args.repo / 'database/seeders/TrimsSeeder.php'
    source = model_path.read_text()
    import_block = re.search(r'public const MODELES_IMPORT_AMERIQUE_DU_NORD = \[(.*?)\n\s*\];', source, re.S)
    if import_block is None:
        raise ValueError('Declaration de modeles additionnels introuvable')
    import_begin = source[:import_block.start()].count('\n') + 1
    import_end = source[:import_block.end()].count('\n') + 1
    models = []
    for line, values in literal_rows(model_path):
        if import_begin < line < import_end:
            if len(values) != 6:
                raise ValueError(f'Modele additionnel inattendu a la ligne {line}')
            values.insert(0, 'toyota')
        if len(values) != 7:
            raise ValueError(f'Modele inattendu a la ligne {line}')
        row = dict(zip(['marque', 'nom', 'generation', 'carrosserie', 'segment', 'annee_debut', 'annee_fin'], values))
        row['slug'] = slug(row['nom'] + ('-' + row['generation'] if row['generation'] else ''))
        row['ligne_source'] = line
        models.append(row)
    trims = []
    for line, values in literal_rows(trim_path):
        if len(values) not in (11, 12):
            raise ValueError(f'Finition inattendue a la ligne {line}')
        row = dict(zip(['marque', 'slug_modele', 'nom', 'code', 'rang', 'variable_carburant', 'variable_motricite', 'puissance_documentee_hp', 'cylindree_documentee_cm3', 'cylindres_documentes', 'boite_documentee', 'description'], values))
        row['ligne_source'] = line
        trims.append(row)
    by_model = collections.defaultdict(list)
    for trim in trims:
        by_model[trim['marque'], trim['slug_modele']].append(trim)
    keys = collections.Counter((row['marque'], row['slug']) for row in models)
    trim_keys = collections.Counter((row['marque'], row['slug_modele'], row['nom']) for row in trims)
    for row in models:
        related = by_model[row['marque'], row['slug']]
        row['nb_finitions'] = len(related)
        row['finitions'] = ' | '.join(trim['nom'] for trim in related)
    brands = []
    for brand in sorted({row['marque'] for row in models}):
        related = [row for row in models if row['marque'] == brand]
        covered = sum(row['nb_finitions'] > 0 for row in related)
        brands.append(dict(marque=brand, nb_entrees=len(related), nb_noms_commerciaux=len({row['nom'] for row in related}), avec_finition=covered, sans_finition=len(related)-covered, nb_finitions=sum(row['nb_finitions'] for row in related), generations_non_renseignees=sum(row['generation'] is None for row in related)))
    groups = collections.defaultdict(list)
    for row in models:
        groups[row['marque'], row['nom']].append(row)
    equal_boundaries = []
    strict_overlaps = []
    for group in groups.values():
        ordered = sorted(group, key=lambda row: row['annee_debut'])
        for left, right in zip(ordered, ordered[1:]):
            transition = dict(marque=left['marque'], nom=left['nom'], generation_precedente=left['generation'], fin=left['annee_fin'], generation_suivante=right['generation'], debut=right['annee_debut'])
            if left['annee_fin'] == right['annee_debut']:
                equal_boundaries.append(transition)
            elif left['annee_fin'] is None or left['annee_fin'] > right['annee_debut']:
                strict_overlaps.append(transition)
    summary = dict(commit=subprocess.check_output(['git', '-C', str(args.repo), 'rev-parse', 'HEAD'], text=True).strip(), methode='Analyse statique des lignes litterales des seeders, sans lecture de la base de production ni execution PHP.', marques=len(brands), noms_commerciaux=len(groups), entrees_modeles=len(models), finitions=len(trims), entrees_avec_finition=sum(row['nb_finitions'] > 0 for row in models), entrees_sans_finition=sum(row['nb_finitions'] == 0 for row in models), marques_sans_finition=sum(row['nb_finitions'] == 0 for row in brands), generations_non_renseignees=sum(row['generation'] is None for row in models), periodes_sans_fin=sum(row['annee_fin'] is None for row in models), cles_modeles_dupliquees=[list(key) for key, count in keys.items() if count > 1], cles_finitions_dupliquees=[list(key) for key, count in trim_keys.items() if count > 1], finitions_sans_modele=[row for row in trims if (row['marque'], row['slug_modele']) not in keys], transitions_meme_annee=equal_boundaries, chevauchements_stricts=strict_overlaps)
    model_fields = ['marque', 'nom', 'generation', 'slug', 'carrosserie', 'segment', 'annee_debut', 'annee_fin', 'nb_finitions', 'finitions', 'ligne_source']
    write_csv(args.output / 'modeles-et-finitions.csv', model_fields, sorted(models, key=lambda row: (row['marque'], row['nom'], row['annee_debut'])))
    write_csv(args.output / 'couverture-par-marque.csv', list(brands[0]), brands)
    (args.output / 'donnees-catalogue.json').write_text(json.dumps(dict(resume=summary, marques=brands, modeles=models, finitions=trims), ensure_ascii=False, indent=2) + '\n')
    print(json.dumps({key: value for key, value in summary.items() if key not in ('transitions_meme_annee',)}, ensure_ascii=False, indent=2))
    print(f'Transitions partageant la meme annee: {len(equal_boundaries)}')


if __name__ == '__main__':
    main()
