# Usage : python3 generer-inventaire.py sortie.csv catalogue.json vehicules.json
# (exports LECTURE SEULE : scripts/verification-deploiement/export-*.sql)
import json,re,csv,collections,sys
d=json.load(open(sys.argv[2])); v=json.load(open(sys.argv[3]))
if 'dump' in d: d=d['dump']
if 'dump' in v: v=v['dump']
pm={m['id']:m for m in d['vehicle_models']}; pp={p['id']:p for p in d['parts']}
br={b['id']:b['name'] for b in d['brands']}; mf={m['id']:m['name'] for m in d['manufacturers']}
mot={m['id']:m for m in v['motorisations']}
reels=[]
for x in v['vehicles']:
    if x['deleted_at'] is None and x['vehicle_model_id']: reels.append(('parc',x['id'],x['vehicle_model_id'],x['manufacturing_year'],x['engine_code'],x['status']))
for x in v['owned_vehicles']:
    if x['deleted_at'] is None and x['vehicle_model_id']:
        ec=x['engine_code'] or (mot.get(x['motorisation_id'] or 0) or {}).get('engine_code')
        reels.append(('garage',x['id'],x['vehicle_model_id'],x['manufacturing_year'] or x['model_year'],ec,''))
VERIF={424,425,426,427,428,478,479,480,481,482,483,484}; OFF={430,434,438}
def cat(f):
    n=f['notes'] or ''
    if f['id'] in OFF: return ('constructeur_url_relue','manufacturer_official',"Aucune pour le verdict : URL constructeur relue le 26/11/2026 (rétro-remplissage, branche feat/preuves-retro-remplissage-notes 69a55b1).","Poser le niveau via la migration de rétro-remplissage si le propriétaire l'accepte.")
    if f['id'] in VERIF: return ('apres_vente_url_relue','aftermarket_crossref',"Page du catalogue électronique Toyota (EPC) ou d'un concessionnaire Toyota agréé nommant 90915-1000x pour le châssis et le moteur ; ou contrôle humain daté et signé sur la pièce démontée.","Rétro-remplissage aftermarket_crossref (reste candidat) puis qualification.")
    if 'Catalogue de correspondance du 18/10/2026' in n or 'Premier document fournisseur' in n:
        return ('document_fournisseur_sans_url','',"EPC Toyota/Lexus ou page de concessionnaire Toyota/Lexus agréé pour le numéro d'origine, au châssis et au moteur de la ligne (URL), ou validation humaine datée et signée avec la source consultée.","Qualifier ; à défaut laisser candidat (niveau aftermarket_crossref si le document fournisseur est archivé avec une URL).")
    if 'MazdaPartsNow' in n or 'Mazda USA' in n:
        return ('site_pieces_mazda_cite_sans_url','',"URL de la page pièce sur un catalogue Mazda d'origine (EPC Mazda) ou chez un concessionnaire Mazda agréé, filtrée sur le modèle et l'année ; vérifier le moteur (SKYACTIV-G atmosphérique / Turbo / Diesel).","Qualifier en priorité : ces lignes touchent des véhicules réels du parc.")
    if 'Bosch' in n:
        return ('catalogue_apres_vente_cite_sans_url','',"Comme ci-dessus : EPC Mazda ou concessionnaire Mazda agréé ; Bosch est un catalogue de l'après-vente (aftermarket_crossref au mieux).","Qualifier.")
    if re.search(r'https?://',n): return ('url_non_relue','',"Relire la page et classer selon l'éditeur.","Relire.")
    return ('aucune_source_citee','',"Toute la preuve est à trouver : EPC constructeur ou concessionnaire agréé (URL), ou validation humaine datée et signée.","Qualifier ou retirer la ligne si aucune source n'est trouvée.")
# Les codes d'une lettre ou d'un chiffre saisis au garage (caractère du VIN) ne sont pas des codes moteur :
# le moteur de compatibilité les traite comme inconnus, l'inventaire aussi.
reels=[(t,i,mid,an,(ec if ec and len(ec)>=3 else None),st) for t,i,mid,an,ec,st in reels]
rows=[]
for f in d['part_fitments']:
    p=pp[f['part_id']]; m=pm[f['vehicle_model_id']]
    touche=[]
    for t,i,mid,an,ec,st in reels:
        if mid!=f['vehicle_model_id']: continue
        if an and ((f['year_from'] and an<f['year_from']) or (f['year_to'] and an>f['year_to'])): continue
        if ec and f['engine_code'] and ec.upper()!=f['engine_code'].upper(): continue
        touche.append(f"{t}:{i} ({an or '?'}, moteur {ec or 'inconnu'})")
    c,niv,req,act=cat(f)
    actif=p['is_active'] and not p['deleted_at']
    stock=p['stock_quantity'] or 0; prix=float(p['selling_price'] or 0)
    prio='P1' if touche else ('P2' if actif and stock>0 else ('P3' if actif else 'P4'))
    rows.append(dict(priorite=prio, fitment_id=f['id'], part_id=p['id'], sku=p['sku'] or '', piece=p['name'], type_piece=p['type'],
        fabricant_piece=mf.get(p['manufacturer_id'],''), piece_active='oui' if actif else 'non', stock=stock, prix_xof=int(prix), valeur_stock_xof=int(stock*prix),
        marque_vehicule=br.get(m['brand_id'],''), modele_slug=m['slug'], modele=m['name'], annee_debut=f['year_from'] or '', annee_fin=f['year_to'] or '',
        moteur=f['engine_code'] or '', source_ligne=f['source'], vehicules_reels_touches=' ; '.join(touche), nb_vehicules_reels=len(touche),
        nature_source_actuelle=c, url_dans_notes=' '.join(re.findall(r'https?://[^\s)»,;]+', f['notes'] or '')),
        niveau_actuel=f.get('evidence_level') or '', niveau_justifie_aujourd_hui=niv, preuve_requise=req, action_proposee=act,
        notes_actuelles=(f['notes'] or '').replace('\n',' ')))
rows.sort(key=lambda r:(r['priorite'],-r['nb_vehicules_reels'],-r['valeur_stock_xof'],-r['stock'],r['sku'],r['modele_slug'],r['fitment_id']))
for i,r in enumerate(rows,1): r['rang']=i
cols=['rang']+[k for k in rows[0] if k!='rang']
w=csv.DictWriter(open(sys.argv[1],'w',newline=''),fieldnames=cols); w.writeheader(); w.writerows(rows)
print(collections.Counter(r['priorite'] for r in rows)); print(collections.Counter(r['nature_source_actuelle'] for r in rows))
for r in rows:
    if r['priorite']=='P1': print(r['rang'],r['fitment_id'],r['sku'],r['modele_slug'],r['moteur'],r['annee_debut'],r['annee_fin'],r['vehicules_reels_touches'],r['nature_source_actuelle'])
print(collections.Counter((r['priorite'],r['sku']) for r in rows))
