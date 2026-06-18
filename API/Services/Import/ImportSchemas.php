<?php
namespace API\Services\Import;

/**
 * Registre des schémas d'import en masse.
 *
 * Chaque entité déclare :
 *   - label    : libellé UI
 *   - table    : table cible
 *   - scoped   : true => la ligne porte etablissement_id (rattachement automatique)
 *   - generate : champs générés si absents (identifiant, mot_de_passe)
 *   - dedup    : colonnes canoniques servant de clé d'unicité (dans l'établissement)
 *   - columns  : map colonne_canonique => définition :
 *        aliases   : en-têtes acceptés (incluant les en-têtes FR d'un export Pronote)
 *        required  : ligne rejetée si vide
 *        transform : 'date' (FR/ISO -> Y-m-d) | 'ouinon' | 'trim' | 'lower' | 'int' | 'float'
 *        default   : valeur injectée si la colonne (NOT NULL) est absente/vide
 *        fk        : ['entity'=>..., 'by'=>'nom|code|identifiant|nomprenom'] résolution vers un id
 *
 * Les alias sont comparés en mode « normalisé » (minuscule, sans accent, sans
 * ponctuation) par BulkImporter::normalizeHeader(), donc « Né(e) le », « ne le »
 * et « date_naissance » tombent tous sur date_naissance.
 */
class ImportSchemas
{
    /** @return array<string,array> */
    public static function all(): array
    {
        return [
            // ─── Utilisateurs ────────────────────────────────────────────────
            'eleve' => [
                'label'    => 'Élèves',
                'table'    => 'eleves',
                'scoped'   => true,
                'generate' => ['identifiant', 'mot_de_passe'],
                'dedup'    => ['mail'],
                'columns'  => [
                    'nom'            => ['aliases' => ['nom', 'name', 'eleve', 'nom eleve', 'nom de famille'], 'required' => true],
                    'prenom'         => ['aliases' => ['prenom', 'firstname', 'prenom eleve'], 'required' => true],
                    'date_naissance' => ['aliases' => ['date naissance', 'ne le', 'nee le', 'naissance', 'date de naissance', 'birthdate', 'ddn'], 'transform' => 'date', 'default' => '2000-01-01'],
                    'lieu_naissance' => ['aliases' => ['lieu naissance', 'lieu de naissance', 'ville naissance'], 'default' => ''],
                    'classe'         => ['aliases' => ['classe', 'class', 'division', 'groupe'], 'default' => ''],
                    'sexe'           => ['aliases' => ['sexe', 'genre', 'civilite', 'civilité'], 'default' => ''],
                    'adresse'        => ['aliases' => ['adresse', 'address', 'adresse postale'], 'default' => ''],
                    'code_postal'    => ['aliases' => ['code postal', 'cp', 'zip'], 'default' => ''],
                    'ville'          => ['aliases' => ['ville', 'city', 'commune'], 'default' => ''],
                    'mail'           => ['aliases' => ['mail', 'email', 'e mail', 'courriel', 'adresse mail', 'mel'], 'transform' => 'trim'],
                    'telephone'      => ['aliases' => ['telephone', 'tel', 'portable', 'mobile', 'gsm'], 'default' => ''],
                ],
            ],
            'professeur' => [
                'label'    => 'Professeurs',
                'table'    => 'professeurs',
                'scoped'   => true,
                'generate' => ['identifiant', 'mot_de_passe'],
                'dedup'    => ['mail'],
                'columns'  => [
                    'nom'                  => ['aliases' => ['nom', 'name', 'nom enseignant'], 'required' => true],
                    'prenom'               => ['aliases' => ['prenom', 'firstname'], 'required' => true],
                    'mail'                 => ['aliases' => ['mail', 'email', 'e mail', 'courriel'], 'transform' => 'trim'],
                    'matiere'              => ['aliases' => ['matiere', 'matière', 'discipline', 'subject'], 'default' => ''],
                    'professeur_principal' => ['aliases' => ['professeur principal', 'prof principal', 'pp'], 'transform' => 'ouinon', 'default' => 'non'],
                    'adresse'              => ['aliases' => ['adresse', 'address'], 'default' => ''],
                    'telephone'            => ['aliases' => ['telephone', 'tel', 'portable'], 'default' => ''],
                ],
            ],
            'parent' => [
                'label'    => 'Parents',
                'table'    => 'parents',
                'scoped'   => true,
                'generate' => ['identifiant', 'mot_de_passe'],
                'dedup'    => ['mail'],
                'columns'  => [
                    'nom'              => ['aliases' => ['nom', 'name', 'nom parent', 'responsable'], 'required' => true],
                    'prenom'           => ['aliases' => ['prenom', 'firstname'], 'required' => true],
                    'mail'             => ['aliases' => ['mail', 'email', 'e mail', 'courriel'], 'transform' => 'trim'],
                    'metier'           => ['aliases' => ['metier', 'profession', 'job'], 'default' => ''],
                    'adresse'          => ['aliases' => ['adresse', 'address'], 'default' => ''],
                    'telephone'        => ['aliases' => ['telephone', 'tel', 'portable'], 'default' => ''],
                    'est_parent_eleve' => ['aliases' => ['est parent eleve', 'parent eleve'], 'transform' => 'ouinon', 'default' => 'oui'],
                ],
            ],
            'vie_scolaire' => [
                'label'    => 'Vie scolaire',
                'table'    => 'vie_scolaire',
                'scoped'   => true,
                'generate' => ['identifiant', 'mot_de_passe'],
                'dedup'    => ['mail'],
                'columns'  => [
                    'nom'       => ['aliases' => ['nom', 'name'], 'required' => true],
                    'prenom'    => ['aliases' => ['prenom', 'firstname'], 'required' => true],
                    'mail'      => ['aliases' => ['mail', 'email', 'e mail', 'courriel'], 'transform' => 'trim'],
                    'telephone' => ['aliases' => ['telephone', 'tel', 'portable'], 'default' => ''],
                ],
            ],

            // ─── Référentiel scolaire ───────────────────────────────────────
            'classe' => [
                'label'   => 'Classes',
                'table'   => 'classes',
                'scoped'  => true,
                'dedup'   => ['nom'],
                'columns' => [
                    'nom'            => ['aliases' => ['nom', 'classe', 'class', 'division', 'name'], 'required' => true],
                    'niveau'         => ['aliases' => ['niveau', 'level', 'grade'], 'default' => ''],
                    'annee_scolaire' => ['aliases' => ['annee scolaire', 'annee', 'année', 'year'], 'default' => ''],
                ],
            ],
            'matiere' => [
                'label'   => 'Matières',
                'table'   => 'matieres',
                'scoped'  => true,
                'dedup'   => ['code'],
                'columns' => [
                    'code'        => ['aliases' => ['code', 'code matiere', 'sigle'], 'required' => true, 'transform' => 'trim'],
                    'nom'         => ['aliases' => ['nom', 'matiere', 'matière', 'libelle', 'libellé', 'discipline', 'name'], 'required' => true],
                    'coefficient' => ['aliases' => ['coefficient', 'coef'], 'transform' => 'float', 'default' => '1'],
                    'couleur'     => ['aliases' => ['couleur', 'color'], 'default' => '#3498db'],
                ],
            ],

            // ─── Données pédagogiques ───────────────────────────────────────
            // Notes : résout élève / matière / professeur par nom dans l'établissement.
            'note' => [
                'label'   => 'Notes',
                'table'   => 'notes',
                'scoped'  => true,
                'columns' => [
                    'id_eleve'        => ['aliases' => ['eleve', 'élève', 'nom eleve', 'nom prenom', 'nom prénom'], 'required' => true, 'fk' => ['entity' => 'eleve', 'by' => 'nomprenom']],
                    'id_matiere'      => ['aliases' => ['matiere', 'matière', 'discipline', 'subject'], 'required' => true, 'fk' => ['entity' => 'matiere', 'by' => 'code_or_nom']],
                    'id_professeur'   => ['aliases' => ['professeur', 'prof', 'enseignant'], 'fk' => ['entity' => 'professeur', 'by' => 'nomprenom']],
                    'note'            => ['aliases' => ['note', 'valeur', 'grade', 'mark'], 'required' => true, 'transform' => 'float'],
                    'note_sur'        => ['aliases' => ['note sur', 'sur', 'bareme', 'barème', 'max'], 'transform' => 'float', 'default' => '20'],
                    'coefficient'     => ['aliases' => ['coefficient', 'coef'], 'transform' => 'float', 'default' => '1'],
                    'type_evaluation' => ['aliases' => ['type evaluation', 'type', 'nature'], 'default' => 'Contrôle'],
                    'date_note'       => ['aliases' => ['date note', 'date', 'le'], 'transform' => 'date', 'default' => ''],
                    'trimestre'       => ['aliases' => ['trimestre', 'periode', 'période', 'term'], 'transform' => 'int', 'default' => '1'],
                    'commentaire'     => ['aliases' => ['commentaire', 'appreciation', 'appréciation', 'comment'], 'default' => ''],
                ],
            ],
            // Devoirs : stocke des NOMS (pas d'id) -> import direct, plus simple.
            'devoir' => [
                'label'   => 'Devoirs / cahier de textes',
                'table'   => 'devoirs',
                'scoped'  => true,
                'columns' => [
                    'titre'          => ['aliases' => ['titre', 'title', 'devoir', 'travail'], 'required' => true],
                    'description'    => ['aliases' => ['description', 'consigne', 'contenu', 'detail'], 'default' => ''],
                    'classe'         => ['aliases' => ['classe', 'class', 'division'], 'required' => true],
                    'nom_matiere'    => ['aliases' => ['matiere', 'matière', 'discipline', 'subject'], 'default' => ''],
                    'nom_professeur' => ['aliases' => ['professeur', 'prof', 'enseignant'], 'default' => ''],
                    'date_ajout'     => ['aliases' => ['date ajout', 'date', 'donne le', 'donné le'], 'transform' => 'date', 'default' => ''],
                    'date_rendu'     => ['aliases' => ['date rendu', 'pour le', 'a rendre', 'à rendre le', 'echeance', 'échéance'], 'transform' => 'date', 'default' => ''],
                ],
            ],
        ];
    }

    public static function get(string $entity): ?array
    {
        return self::all()[$entity] ?? null;
    }

    /** Liste [key => label] pour le sélecteur d'entité. */
    public static function options(): array
    {
        $out = [];
        foreach (self::all() as $key => $def) {
            $out[$key] = $def['label'];
        }
        return $out;
    }
}
