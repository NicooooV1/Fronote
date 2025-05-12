<?php
/**
 * Classe pour la génération de documents PDF
 * Utilise la librairie FPDF
 */
class PDF {
    private $pdf;
    private $title;
    private $headerInfo;
    private $footerText;
    
    /**
     * Constructeur
     * @param string $title Titre du document
     * @param string $orientation Orientation (P = portrait, L = paysage)
     * @param string $unit Unité (pt, mm, cm, in)
     * @param string $format Format (A4, A5, Letter, etc.)
     */
    public function __construct($title = '', $orientation = 'P', $unit = 'mm', $format = 'A4') {
        // Vérifier si la classe FPDF existe
        if (!class_exists('FPDF')) {
            require_once ROOT_PATH . '/lib/fpdf/fpdf.php';
        }
        
        $this->pdf = new FPDF($orientation, $unit, $format);
        $this->title = $title;
        $this->headerInfo = [];
        $this->footerText = APP_NAME . ' - Document généré le ' . date(DATETIME_FORMAT);
        
        // Configuration par défaut
        $this->pdf->SetAuthor(APP_NAME);
        $this->pdf->SetTitle($title);
        $this->pdf->SetCreator(APP_NAME);
        $this->pdf->SetAutoPageBreak(true, 15);
        
        // Ajouter une page
        $this->pdf->AddPage();
    }
    
    /**
     * Définit les informations d'en-tête
     * @param array $headerInfo Informations d'en-tête (logo, titre, sous-titre)
     * @return void
     */
    public function setHeaderInfo($headerInfo) {
        $this->headerInfo = $headerInfo;
    }
    
    /**
     * Définit le texte de pied de page
     * @param string $footerText Texte de pied de page
     * @return void
     */
    public function setFooterText($footerText) {
        $this->footerText = $footerText;
    }
    
    /**
     * Ajoute un en-tête au document
     * @param string $title Titre de l'en-tête
     * @param string $subtitle Sous-titre
     * @param string $logoPath Chemin du logo
     * @return void
     */
    public function addHeader($title = null, $subtitle = null, $logoPath = null) {
        $title = $title ?? $this->title;
        $subtitle = $subtitle ?? '';
        
        if ($logoPath !== null && file_exists($logoPath)) {
            // Ajouter le logo
            $this->pdf->Image($logoPath, 10, 10, 30);
            $this->pdf->SetX(45);
        }
        
        // Titre
        $this->pdf->SetFont('Arial', 'B', 16);
        $this->pdf->Cell(0, 10, utf8_decode($title), 0, 1, 'L');
        
        // Sous-titre si défini
        if (!empty($subtitle)) {
            $this->pdf->SetFont('Arial', 'I', 12);
            $this->pdf->Cell(0, 6, utf8_decode($subtitle), 0, 1, 'L');
        }
        
        // Date
        $this->pdf->SetFont('Arial', '', 10);
        $this->pdf->Cell(0, 6, 'Date: ' . date(DATE_FORMAT), 0, 1, 'R');
        
        // Ligne de séparation
        $this->pdf->Line(10, $this->pdf->GetY() + 2, 200, $this->pdf->GetY() + 2);
        $this->pdf->Ln(5);
    }
    
    /**
     * Ajoute un titre au document
     * @param string $title Titre
     * @param int $level Niveau du titre (1-3)
     * @return void
     */
    public function addTitle($title, $level = 1) {
        // Définir la taille selon le niveau
        switch ($level) {
            case 1:
                $this->pdf->SetFont('Arial', 'B', 14);
                break;
                
            case 2:
                $this->pdf->SetFont('Arial', 'B', 12);
                break;
                
            case 3:
                $this->pdf->SetFont('Arial', 'BI', 11);
                break;
                
            default:
                $this->pdf->SetFont('Arial', 'B', 11);
                break;
        }
        
        $this->pdf->Cell(0, 10, utf8_decode($title), 0, 1, 'L');
        $this->pdf->Ln(1);
    }
    
    /**
     * Ajoute un paragraphe au document
     * @param string $text Texte du paragraphe
     * @param int $fontSize Taille de la police
     * @return void
     */
    public function addParagraph($text, $fontSize = 11) {
        $this->pdf->SetFont('Arial', '', $fontSize);
        $this->pdf->MultiCell(0, 6, utf8_decode($text), 0, 'J');
        $this->pdf->Ln(3);
    }
    
    /**
     * Ajoute une liste au document
     * @param array $items Éléments de la liste
     * @param int $fontSize Taille de la police
     * @return void
     */
    public function addList($items, $fontSize = 11) {
        $this->pdf->SetFont('Arial', '', $fontSize);
        
        foreach ($items as $item) {
            $this->pdf->Cell(5, 6, chr(149), 0, 0, 'L'); // Puce
            $this->pdf->MultiCell(0, 6, utf8_decode($item), 0, 'L');
        }
        
        $this->pdf->Ln(3);
    }
    
    /**
     * Ajoute un tableau au document
     * @param array $headers En-têtes du tableau
     * @param array $data Données du tableau
     * @param array $widths Largeurs des colonnes
     * @return void
     */
    public function addTable($headers, $data, $widths = null) {
        // Calculer les largeurs automatiquement si non définies
        if ($widths === null) {
            $pageWidth = $this->pdf->GetPageWidth() - 20; // Marges
            $count = count($headers);
            $widths = array_fill(0, $count, $pageWidth / $count);
        }
        
        // En-têtes
        $this->pdf->SetFont('Arial', 'B', 11);
        $this->pdf->SetFillColor(220, 220, 220);
        
        for ($i = 0; $i < count($headers); $i++) {
            $this->pdf->Cell($widths[$i], 7, utf8_decode($headers[$i]), 1, 0, 'C', true);
        }
        $this->pdf->Ln();
        
        // Données
        $this->pdf->SetFont('Arial', '', 10);
        $this->pdf->SetFillColor(245, 245, 245);
        
        $fill = false;
        foreach ($data as $row) {
            // Conserver la position Y initiale pour cette ligne
            $initialY = $this->pdf->GetY();
            $maxHeight = 7; // Hauteur minimale de la ligne
            
            // Calculer la hauteur maximale nécessaire pour cette ligne
            for ($i = 0; $i < count($row); $i++) {
                $cellContent = $row[$i] ?? '';
                $cellHeight = $this->calculateCellHeight($cellContent, $widths[$i]);
                $maxHeight = max($maxHeight, $cellHeight);
            }
            
            // Dessiner chaque cellule avec la même hauteur
            for ($i = 0; $i < count($row); $i++) {
                $cellContent = $row[$i] ?? '';
                $this->pdf->SetY($initialY);
                $this->pdf->SetX(10 + array_sum(array_slice($widths, 0, $i)));
                $this->pdf->MultiCell($widths[$i], 7, utf8_decode($cellContent), 1, 'L', $fill);
            }
            
            // Avancer à la ligne suivante
            $this->pdf->SetY($initialY + $maxHeight);
            
            $fill = !$fill; // Alterner les couleurs
        }
        
        $this->pdf->Ln(5);
    }
    
    /**
     * Calcule la hauteur nécessaire pour une cellule
     * @param string $text Texte de la cellule
     * @param float $width Largeur de la cellule
     * @return float Hauteur calculée
     */
    private function calculateCellHeight($text, $width) {
        $text = utf8_decode($text);
        $cw = $this->pdf->GetStringWidth($text);
        $numLines = ceil($cw / $width);
        return $numLines * 7; // Hauteur d'une ligne
    }
    
    /**
     * Ajoute une image au document
     * @param string $path Chemin de l'image
     * @param float $x Position X
     * @param float $y Position Y
     * @param float $width Largeur
     * @param float $height Hauteur
     * @param string $caption Légende
     * @return void
     */
    public function addImage($path, $x = null, $y = null, $width = null, $height = null, $caption = '') {
        if (!file_exists($path)) {
            return;
        }
        
        // Positionner l'image si les coordonnées sont fournies
        if ($x !== null && $y !== null) {
            $this->pdf->Image($path, $x, $y, $width, $height);
        } else {
            // Centrer l'image
            if ($width === null) {
                $width = 120; // Largeur par défaut
            }
            
            $pageWidth = $this->pdf->GetPageWidth();
            $x = ($pageWidth - $width) / 2;
            
            $this->pdf->Image($path, $x, $this->pdf->GetY(), $width, $height);
            $this->pdf->Ln($height + 5);
        }
        
        // Ajouter une légende si fournie
        if (!empty($caption)) {
            $this->pdf->SetFont('Arial', 'I', 9);
            $this->pdf->Cell(0, 5, utf8_decode($caption), 0, 1, 'C');
            $this->pdf->Ln(2);
        }
    }
    
    /**
     * Ajoute un saut de page
     * @return void
     */
    public function addPageBreak() {
        $this->pdf->AddPage();
    }
    
    /**
     * Ajoute un pied de page
     * @param string $text Texte du pied de page
     * @return void
     */
    public function addFooter($text = null) {
        $text = $text ?? $this->footerText;
        
        $this->pdf->SetY(-15);
        $this->pdf->SetFont('Arial', 'I', 8);
        $this->pdf->Cell(0, 10, utf8_decode($text), 0, 0, 'C');
        $this->pdf->Cell(0, 10, 'Page ' . $this->pdf->PageNo(), 0, 0, 'R');
    }
    
    /**
     * Génère le document PDF
     * @param string $filename Nom du fichier (null pour afficher)
     * @param string $destination Destination (I = navigateur, D = téléchargement, F = fichier, S = string)
     * @return mixed Contenu du PDF si destination = S, sinon void
     */
    public function output($filename = null, $destination = 'I') {
        // Ajouter le pied de page à toutes les pages
        $totalPages = $this->pdf->PageNo();
        for ($i = 1; $i <= $totalPages; $i++) {
            $this->pdf->setPage($i);
            $this->addFooter();
        }
        
        return $this->pdf->Output($filename, $destination);
    }
    
    /**
     * Génère un export PDF pour un devoir
     * @param array $devoir Données du devoir
     * @param array $rendus Rendus du devoir (optionnel)
     * @param string $filename Nom du fichier (null pour afficher)
     * @param string $destination Destination (I = navigateur, D = téléchargement, F = fichier, S = string)
     * @return mixed Contenu du PDF si destination = S, sinon void
     */
    public function generateDevoirPDF($devoir, $rendus = null, $filename = null, $destination = 'I') {
        $this->title = "Devoir: " . $devoir['titre'];
        
        // Ajouter l'en-tête
        $this->addHeader($this->title, "Classe: " . $devoir['classe_nom']);
        
        // Informations du devoir
        $this->addTitle("Informations", 1);
        
        $infoTable = [
            ["Auteur", $devoir['auteur_prenom'] . ' ' . $devoir['auteur_nom']],
            ["Date de création", formatDate($devoir['date_creation'], 'datetime')],
            ["Date de début", formatDate($devoir['date_debut'], 'datetime')],
            ["Date limite", formatDate($devoir['date_limite'], 'datetime')],
            ["Statut", getStatutDevoir($devoir['statut'])],
            ["Travail de groupe", $devoir['travail_groupe'] ? 'Oui' : 'Non'],
        ];
        
        $this->addTable(['Propriété', 'Valeur'], $infoTable, [40, 120]);
        
        // Description et instructions
        $this->addTitle("Description", 2);
        $this->addParagraph($devoir['description']);
        
        if (!empty($devoir['instructions'])) {
            $this->addTitle("Instructions", 2);
            $this->addParagraph($devoir['instructions']);
        }
        
        // Pièces jointes
        if (!empty($devoir['pieces_jointes'])) {
            $this->addTitle("Pièces jointes", 2);
            
            $piecesJointesData = [];
            foreach ($devoir['pieces_jointes'] as $pj) {
                $piecesJointesData[] = [$pj['nom'], $pj['type'], formatDate($pj['date_ajout'], 'date')];
            }
            
            $this->addTable(['Nom', 'Type', 'Date d\'ajout'], $piecesJointesData, [80, 40, 40]);
        }
        
        // Rendus si disponibles
        if ($rendus !== null && !empty($rendus)) {
            $this->addPageBreak();
            $this->addTitle("Rendus des élèves", 1);
            
            $rendusData = [];
            foreach ($rendus as $rendu) {
                $rendusData[] = [
                    $rendu['eleve_nom'],
                    formatDate($rendu['date_rendu'], 'datetime'),
                    getStatutDevoir($rendu['statut']),
                    $rendu['note'] ?? 'Non noté'
                ];
            }
            
            $this->addTable(['Élève', 'Date de rendu', 'Statut', 'Note'], $rendusData, [60, 50, 40, 30]);
        }
        
        return $this->output($filename, $destination);
    }
    
    /**
     * Génère un export PDF pour le cahier de texte d'une période
     * @param array $seances Séances à inclure
     * @param string $dateDebut Date de début de la période
     * @param string $dateFin Date de fin de la période
     * @param string $classe Nom de la classe
     * @param string $filename Nom du fichier (null pour afficher)
     * @param string $destination Destination (I = navigateur, D = téléchargement, F = fichier, S = string)
     * @return mixed Contenu du PDF si destination = S, sinon void
     */
    public function generateCahierPDF($seances, $dateDebut, $dateFin, $classe, $filename = null, $destination = 'I') {
        $periode = "du " . formatDate($dateDebut, 'date') . " au " . formatDate($dateFin, 'date');
        $this->title = "Cahier de texte - " . $classe;
        
        // Ajouter l'en-tête
        $this->addHeader($this->title, "Période: " . $periode);
        
        // Regrouper les séances par jour
        $seancesParJour = [];
        foreach ($seances as $seance) {
            $jour = date('Y-m-d', strtotime($seance['date_debut']));
            
            if (!isset($seancesParJour[$jour])) {
                $seancesParJour[$jour] = [];
            }
            
            $seancesParJour[$jour][] = $seance;
        }
        
        // Parcourir les jours
        ksort($seancesParJour);
        
        foreach ($seancesParJour as $jour => $seancesJour) {
            $dateFormatee = formatDate($jour, 'date');
            $jourSemaine = getJourSemaine(date('w', strtotime($jour)));
            
            $this->addTitle("$jourSemaine $dateFormatee", 1);
            
            // Trier les séances par heure de début
            usort($seancesJour, function($a, $b) {
                return strtotime($a['date_debut']) - strtotime($b['date_debut']);
            });
            
            foreach ($seancesJour as $seance) {
                $heureDebut = date('H:i', strtotime($seance['date_debut']));
                $heureFin = date('H:i', strtotime($seance['date_fin']));
                
                $this->addTitle("{$seance['matiere_nom']} ({$heureDebut} - {$heureFin})", 2);
                
                if (!empty($seance['chapitre_titre'])) {
                    $this->pdf->SetFont('Arial', 'I', 11);
                    $this->pdf->Cell(0, 6, utf8_decode("Chapitre: {$seance['chapitre_titre']}"), 0, 1, 'L');
                    $this->pdf->Ln(2);
                }
                
                $this->pdf->SetFont('Arial', '', 10);
                $this->pdf->MultiCell(0, 6, utf8_decode($seance['contenu']), 0, 'J');
                
                if (!empty($seance['objectifs'])) {
                    $this->pdf->Ln(2);
                    $this->pdf->SetFont('Arial', 'B', 10);
                    $this->pdf->Cell(0, 6, utf8_decode("Objectifs:"), 0, 1, 'L');
                    $this->pdf->SetFont('Arial', '', 10);
                    $this->pdf->MultiCell(0, 6, utf8_decode($seance['objectifs']), 0, 'J');
                }
                
                if (!empty($seance['ressources'])) {
                    $this->pdf->Ln(2);
                    $this->pdf->SetFont('Arial', 'B', 10);
                    $this->pdf->Cell(0, 6, utf8_decode("Ressources:"), 0, 1, 'L');
                    
                    foreach ($seance['ressources'] as $ressource) {
                        $this->pdf->SetFont('Arial', '', 10);
                        $this->pdf->Cell(5, 6, chr(149), 0, 0, 'L'); // Puce
                        $this->pdf->Cell(0, 6, utf8_decode($ressource['titre']), 0, 1, 'L');
                    }
                }
                
                $this->pdf->Ln(5);
            }
            
            // Saut de page sauf pour le dernier jour
            end($seancesParJour);
            if ($jour !== key($seancesParJour)) {
                $this->addPageBreak();
            }
        }
        
        return $this->output($filename, $destination);
    }
}