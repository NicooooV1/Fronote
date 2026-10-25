#!/usr/bin/env python3
"""Assemble vitrine.html depuis le gabarit + le catalogue réel des modules."""
import json, sys, html
S = sys.argv[1]
DEMO = 'https://demo.nicov1.fr'
tpl = open(f'{S}/vitrine.tpl.html', encoding='utf-8').read()
mods = json.load(open(f'{S}/modules.json', encoding='utf-8'))
SIMPLE = {'notes', 'absences', 'devoirs', 'messagerie', 'agenda', 'emploi_du_temps', 'cahierdetextes', 'bulletins', 'trombinoscope', 'notifications'}
CATMAP = {'scolaire': 'pedagogie', 'vie_scolaire': 'vie', 'communication': 'communication', 'administration': 'administration',
          'etablissement': 'etablissement', 'outils': 'services', 'logistique': 'services', 'systeme': 'systeme', 'navigation': 'systeme'}
CATS = {'pedagogie': 'Pédagogie', 'vie': 'Vie scolaire', 'communication': 'Communication', 'administration': 'Administration',
        'etablissement': 'Établissement', 'services': 'Services', 'systeme': 'Système'}
order = list(CATS)
# Descriptions reformulées pour le public (les module.json contiennent du jargon interne).
DESC = {'marketplace': 'Découverte et installation signée de modules complémentaires',
        'intelligence': 'Repérage précoce du risque de décrochage, signaux faibles et recommandations'}
out = []
for m in mods:
    socle = m['key'] in SIMPLE or bool(m.get('core'))
    out.append({'name': m['name'], 'desc': DESC.get(m['key'], (m['desc'] or '').rstrip('.')), 'cat': CATMAP.get(m['category'], 'services'), 'socle': socle})
out.sort(key=lambda x: (not x['socle'], order.index(x['cat']), x['name'].lower()))

e = lambda s: html.escape(s, quote=True)
roles = [
    ('direction', 'Direction', 'Claire Delmas, principale', ['Tableau de pilotage : effectifs, absentéisme, moyennes', 'Créer un utilisateur, gérer les classes', 'Publier une annonce à tout le collège']),
    ('prof', 'Professeur', 'Marc Lefort, maths · PP de 4A', ['Son emploi du temps et l\'appel en un clic', 'Saisir et consulter les notes', 'Répondre à un parent dans la messagerie']),
    ('eleve', 'Élève', 'Léa Martin, 4A', ['Ses moyennes par matière', 'Les devoirs à rendre cette semaine', 'Écrire à son professeur']),
    ('parent', 'Parent', 'Nadia Martin, deux enfants', ['Passer de Léa (4A) à Hugo (6B)', 'Suivre notes, absences et retards', 'Lire les messages du collège']),
    ('cpe', 'Vie scolaire', 'Sophie Rivière, CPE', ['Absences et justificatifs à traiter', 'Retards, incidents et sanctions', 'Agenda des conseils et sorties']),
]
cards = ''.join(
    f'<a class="role reveal" href="{DEMO}/demo/?role={slug}"><span class="r-k">profil</span><h3>{e(lib)}</h3><p class="who">{e(who)}</p>'
    f'<ul>{"".join(f"<li>{e(x)}</li>" for x in todo)}</ul><span class="go">Entrer en tant que {e(lib.lower())} →</span></a>'
    for slug, lib, who, todo in roles)

# Visite guidée : (id, onglet, titre, [captures (fichier, légende, url)], points)
tour = [
    ('direction', 'Direction', 'Piloter l\'établissement d\'un coup d\'œil',
     [('direction-pilotage', 'Tableau de pilotage de la direction', '/admin/dashboard'), ('direction-accueil', 'Accueil personnalisable par widgets', '/accueil')],
     ['Indicateurs en direct : effectifs, absentéisme, moyenne générale, remplissage des notes', 'Actions rapides : créer ou importer des utilisateurs, publier une annonce', 'Activation des modules et réglage fin des droits par rôle', 'Journal d\'audit et outils RGPD intégrés']),
    ('prof', 'Professeur', 'Moins d\'administratif, plus de cours',
     [('prof-emploi-du-temps', 'Emploi du temps de la semaine, appel depuis chaque cours', '/emploi_du_temps'), ('prof-notes', 'Notes attribuées, filtrables par classe et matière', '/notes'), ('prof-cahier-de-textes', 'Cahier de textes et devoirs', '/cahierdetextes'), ('prof-messagerie', 'Messagerie avec élèves, familles et collègues', '/messagerie')],
     ['L\'appel se lance directement depuis le cours de l\'emploi du temps', 'Saisie des notes par évaluation, coefficients, export CSV et PDF', 'Devoirs publiés une fois, visibles par les élèves et leurs familles', 'Messages individuels, à la classe ou à l\'équipe pédagogique']),
    ('eleve', 'Élève', 'Tout son collège dans une page',
     [('eleve-notes', 'Moyenne générale et moyennes par matière', '/notes'), ('eleve-accueil', 'Accueil : devoirs, notes récentes, événements', '/accueil'), ('eleve-emploi-du-temps', 'Emploi du temps de la classe', '/emploi_du_temps')],
     ['Moyennes calculées en temps réel, par trimestre', 'Devoirs à rendre classés par date', 'Emploi du temps avec salles et professeurs', 'Notifications à chaque nouvelle note ou nouveau devoir']),
    ('parent', 'Parent', 'Suivre la scolarité sans chercher',
     [('parent-accueil', 'Résumé de chaque enfant dès l\'accueil', '/accueil'), ('parent-absences', 'Absences et retards, justifiés ou non', '/absences')],
     ['Un seul compte pour tous ses enfants, même dans des classes différentes', 'Notes, absences, devoirs et bulletins de chacun', 'Messages avec les professeurs et la vie scolaire', 'Réunions parents-professeurs et autorisations en ligne']),
    ('cpe', 'Vie scolaire', 'Absences, retards, discipline : sous contrôle',
     [('cpe-absences', 'Gestion des absences : filtres, justificatifs, validation', '/absences'), ('cpe-accueil', 'Accueil vie scolaire', '/accueil')],
     ['Liste des absences filtrable par période, classe et justification', 'Retards, justificatifs, validation et statistiques', 'Incidents, sanctions et retenues tracés', 'Fiches santé et infirmerie, chiffrées']),
]
tabs = ''.join(f'<button class="tab" role="tab" id="t-{tid}" aria-controls="p-{tid}" aria-selected="{"true" if i == 0 else "false"}">{e(lib)}</button>' for i, (tid, lib, *_r) in enumerate(tour))
panels = ''
for i, (tid, lib, titre, shots, pts) in enumerate(tour):
    f0, c0, u0 = shots[0]
    thumbs = ''.join(f'<button type="button" class="thumb" aria-pressed="{"true" if j == 0 else "false"}" data-src="/assets/shots/{f}.webp" data-cap="{e(c)}" data-url="college-victor-hugo{u}" aria-label="{e(c)}"><img src="/assets/shots/{f}.webp" alt="" loading="lazy" width="1920" height="1200"></button>' for j, (f, c, u) in enumerate(shots))
    panels += (f'<div class="panel" role="tabpanel" id="p-{tid}" aria-labelledby="t-{tid}"{"" if i == 0 else " hidden"}>'
               f'<div><div class="browser"><div class="bar"><i></i><i></i><i></i><span class="js-url">college-victor-hugo{u0}</span></div>'
               f'<img class="js-main" src="/assets/shots/{f0}.webp" alt="Capture d\'écran de Fronote, espace {e(lib.lower())}" loading="lazy" width="1920" height="1200"></div>'
               f'<p class="cap js-cap">{e(c0)}</p>{"<div class=thumbs>" + thumbs + "</div>" if len(shots) > 1 else ""}</div>'
               f'<div><span class="eyebrow">{e(lib)}</span><h3>{e(titre)}</h3><ul class="feat">{"".join(f"<li>{e(p)}</li>" for p in pts)}</ul>'
               f'<a class="btn" href="{DEMO}/demo/?role={tid}">Essayer l\'espace {e(lib.lower())}</a></div></div>')

page = (tpl.replace('__ROLE_CARDS__', cards).replace('__TABS__', tabs).replace('__PANELS__', panels)
        .replace('__MODULES_JSON__', json.dumps(out, ensure_ascii=False)).replace('__CATS_JSON__', json.dumps(CATS, ensure_ascii=False))
        .replace('__NB_MODULES__', str(len(out))).replace('__DEMO__', DEMO))
assert '__' not in page.replace('__proto__', ''), [l for l in page.splitlines() if '__' in l][:3]
open(f'{S}/vitrine.html', 'w', encoding='utf-8').write(page)
print(len(out), 'modules,', len(page), 'octets')
