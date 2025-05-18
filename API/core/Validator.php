<?php
/**
 * Classe de validation de formulaire
 */
class Validator
{
    private $data;
    private $errors = [];
    private $rules = [];
    
    /**
     * Constructeur
     * 
     * @param array $data Données à valider
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }
    
    /**
     * Ajoute une règle de validation
     * 
     * @param string $field     Nom du champ
     * @param string $rule      Nom de la règle
     * @param mixed  $options   Options de la règle
     * @param string $message   Message d'erreur personnalisé
     * @return Validator Instance courante pour chaînage
     */
    public function rule($field, $rule, $options = null, $message = null)
    {
        $this->rules[$field][] = [
            'rule' => $rule,
            'options' => $options,
            'message' => $message
        ];
        
        return $this;
    }
    
    /**
     * Valide les données selon les règles définies
     * 
     * @return bool True si toutes les règles sont respectées
     */
    public function validate()
    {
        $this->errors = [];
        
        foreach ($this->rules as $field => $rules) {
            $value = isset($this->data[$field]) ? $this->data[$field] : null;
            
            foreach ($rules as $rule) {
                if (!$this->validateRule($field, $value, $rule)) {
                    break; // Passer au champ suivant si une règle échoue
                }
            }
        }
        
        return empty($this->errors);
    }
    
    /**
     * Valide une règle spécifique
     * 
     * @param string $field  Nom du champ
     * @param mixed  $value  Valeur du champ
     * @param array  $rule   Règle à valider
     * @return bool True si la règle est respectée
     */
    private function validateRule($field, $value, $rule)
    {
        $name = $rule['rule'];
        $options = $rule['options'];
        $message = $rule['message'];
        
        switch ($name) {
            case 'required':
                if (empty($value) && $value !== '0') {
                    $this->addError($field, $message ?? "Le champ $field est obligatoire");
                    return false;
                }
                break;
                
            case 'email':
                if (!empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $this->addError($field, $message ?? "Le champ $field doit être une adresse email valide");
                    return false;
                }
                break;
                
            case 'min':
                if (!empty($value) && strlen($value) < $options) {
                    $this->addError($field, $message ?? "Le champ $field doit contenir au moins $options caractères");
                    return false;
                }
                break;
                
            case 'max':
                if (!empty($value) && strlen($value) > $options) {
                    $this->addError($field, $message ?? "Le champ $field ne peut pas dépasser $options caractères");
                    return false;
                }
                break;
                
            case 'numeric':
                if (!empty($value) && !is_numeric($value)) {
                    $this->addError($field, $message ?? "Le champ $field doit être numérique");
                    return false;
                }
                break;
                
            case 'integer':
                if (!empty($value) && !filter_var($value, FILTER_VALIDATE_INT)) {
                    $this->addError($field, $message ?? "Le champ $field doit être un nombre entier");
                    return false;
                }
                break;
                
            case 'regex':
                if (!empty($value) && !preg_match($options, $value)) {
                    $this->addError($field, $message ?? "Le champ $field est invalide");
                    return false;
                }
                break;
                
            case 'date':
                $format = is_array($options) ? $options['format'] : 'Y-m-d';
                if (!empty($value)) {
                    $date = DateTime::createFromFormat($format, $value);
                    if (!$date || $date->format($format) !== $value) {
                        $this->addError($field, $message ?? "Le champ $field doit être une date valide au format $format");
                        return false;
                    }
                }
                break;
                
            case 'in':
                $allowedValues = is_array($options) ? $options : [$options];
                if (!empty($value) && !in_array($value, $allowedValues)) {
                    $this->addError($field, $message ?? "La valeur du champ $field n'est pas autorisée");
                    return false;
                }
                break;
                
            case 'callback':
                if (is_callable($options) && !$options($value)) {
                    $this->addError($field, $message ?? "Le champ $field est invalide");
                    return false;
                }
                break;
        }
        
        return true;
    }
    
    /**
     * Ajoute une erreur de validation
     * 
     * @param string $field   Nom du champ
     * @param string $message Message d'erreur
     */
    private function addError($field, $message)
    {
        $this->errors[$field] = $message;
    }
    
    /**
     * Retourne toutes les erreurs de validation
     * 
     * @return array Erreurs de validation
     */
    public function getErrors()
    {
        return $this->errors;
    }
    
    /**
     * Retourne l'erreur pour un champ spécifique
     * 
     * @param string $field Nom du champ
     * @return string|null Message d'erreur ou null
     */
    public function getError($field)
    {
        return isset($this->errors[$field]) ? $this->errors[$field] : null;
    }
    
    /**
     * Vérifie si un champ a une erreur
     * 
     * @param string $field Nom du champ
     * @return bool True si le champ a une erreur
     */
    public function hasError($field)
    {
        return isset($this->errors[$field]);
    }
    
    /**
     * Vérifie si le formulaire a des erreurs
     * 
     * @return bool True si le formulaire a des erreurs
     */
    public function hasErrors()
    {
        return !empty($this->errors);
    }
}
