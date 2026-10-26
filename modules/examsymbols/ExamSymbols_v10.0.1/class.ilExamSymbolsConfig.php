<?php
/**
 * ExamSymbols Config - Konfiguration für Sonderzeichen-Templates
 * Part of ExtendedTestSettings Plugin
 */

class ilExamSymbolsConfig
{
    protected ilSetting $settings;

    public function __construct(string $namespace)
    {
        $this->settings = new ilSetting($namespace);
    }

    public function get(string $key, string $default = ""): string
    {
        return $this->settings->get($key, $default);
    }

    public function set(string $key, string $value): void
    {
        $this->settings->set($key, $value);
    }

    /**
     * Verfügbare Symbol-Templates
     * 
     * Diese Templates werden sowohl in PHP (für Settings) als auch 
     * in JavaScript (für die Symbol-Palette) verwendet
     */
    public function getAvailableTemplates(): array
    {
        return [
            "math" => [
                "label" => "Mathematik/Wissenschaft",
                "description" => "Mathematische Symbole, griechische Buchstaben, wissenschaftliche Notation",
                "symbols" => [
                    // Grundlegende mathematische Symbole
                    "π", "√", "∑", "∞", "≈", "≠", "≤", "≥", "±", "∆", 
                    "∫", "∂", "→", "↔", "∈", "∉", "∅", "²", "³", "°",
                    
                    // Griechische Buchstaben (klein)
                    "α", "β", "γ", "δ", "ε", "ζ", "η", "θ", "ι", "κ",
                    "λ", "μ", "ν", "ξ", "ο", "π", "ρ", "σ", "τ", "υ",
                    "φ", "χ", "ψ", "ω",
                    
                    // Griechische Buchstaben (groß)
                    "Α", "Β", "Γ", "Δ", "Ε", "Ζ", "Η", "Θ", "Ι", "Κ",
                    "Λ", "Μ", "Ν", "Ξ", "Ο", "Π", "Ρ", "Σ", "Τ", "Υ",
                    "Φ", "Χ", "Ψ", "Ω"
                ]
            ],
            "spanish" => [
    "label" => "Spanisch",
    "description" => "Spanische Akzente und Sonderzeichen",
    "symbols" => [
        // Reihe 1: Vokale groß mit Akzent (5 Tasten)
        "Á", "É", "Í", "Ó", "Ú",
        
        // Reihe 2: Vokale klein mit Akzent (5 Tasten)  
        "á", "é", "í", "ó", "ú",
        
        // Reihe 3: Ñ ñ ¿ ¡ (4 Tasten)
        "Ñ", "ñ", "¿", "¡"
    ]
],
            "italian" => [
                "label" => "Italienisch", 
                "description" => "Italienische Akzente und Sonderzeichen",
                "symbols" => [
                    // Akzente (grave und acute)
                    "à", "è", "ì", "ò", "ù", 
                    "é", "í", "ó", 
                    "À", "È", "Ì", "Ò", "Ù",
                    "É", "Í", "Ó"
                ]
            ],
            "german" => [
                "label" => "Deutsch",
                "description" => "Deutsche Umlaute und Eszett", 
                "symbols" => [
                    "ä", "ö", "ü", "ß", 
                    "Ä", "Ö", "Ü"
                ]
            ],
            "french" => [
                "label" => "Französisch",
                "description" => "Französische Akzente und Sonderzeichen",
                "symbols" => [
                    // Akzente
                    "à", "â", "é", "è", "ê", "ë", "î", "ï", "ô", "ù", "û", "ü", "ÿ", "ç",
                    "À", "Â", "É", "È", "Ê", "Ë", "Î", "Ï", "Ô", "Ù", "Û", "Ü", "Ÿ", "Ç",
                    
                    // Ligaturen
                    "æ", "œ", "Æ", "Œ"
                ]
            ],
            "greek" => [
                "label" => "Griechisch",
                "description" => "Vollständiges griechisches Alphabet",
                "symbols" => [
                    // Kleinbuchstaben
                    "α", "β", "γ", "δ", "ε", "ζ", "η", "θ", "ι", "κ",
                    "λ", "μ", "ν", "ξ", "ο", "π", "ρ", "σ", "ς", "τ",
                    "υ", "φ", "χ", "ψ", "ω",
                    
                    // Großbuchstaben
                    "Α", "Β", "Γ", "Δ", "Ε", "Ζ", "Η", "Θ", "Ι", "Κ",
                    "Λ", "Μ", "Ν", "Ξ", "Ο", "Π", "Ρ", "Σ", "Τ", "Υ",
                    "Φ", "Χ", "Ψ", "Ω"
                ]
            ],
            "chemistry" => [
                "label" => "Chemie",
                "description" => "Chemische Symbole und Notation",
                "symbols" => [
                    // Pfeile für Reaktionen
                    "→", "←", "↔", "⇌", "↑", "↓",
                    
                    // Bindungen
                    "−", "≡", "∼",
                    
                    // Ladungen
                    "⁺", "⁻", "²⁺", "³⁺", "²⁻", "³⁻",
                    
                    // Zustände
                    "°", "∆", 
                    
                    // Griechische Buchstaben (häufig in Chemie)
                    "α", "β", "γ", "δ", "π", "σ", "Δ", "Π", "Σ"
                ]
            ],
            "physics" => [
                "label" => "Physik",
                "description" => "Physikalische Symbole und Notation",
                "symbols" => [
                    // Mathematische Symbole
                    "∞", "∝", "≈", "≡", "≠", "≤", "≥", "±", "∓",
                    
                    // Integrale und Differentiale  
                    "∫", "∮", "∂", "∇", "∆",
                    
                    // Vektoren
                    "→", "⃗", "∙", "×",
                    
                    // Griechische Buchstaben (wichtig in Physik)
                    "α", "β", "γ", "δ", "ε", "θ", "λ", "μ", "ν", "ρ", "σ", "τ", "φ", "ψ", "ω",
                    "Α", "Β", "Γ", "Δ", "Θ", "Λ", "Μ", "Π", "Σ", "Φ", "Ψ", "Ω",
                    
                    // Einheiten und Konstanten  
                    "°", "′", "″", "ℏ", "ħ"
                ]
            ]
        ];
    }

    /**
     * Symbole für ein bestimmtes Template laden
     */
    public function getSymbolsForTemplate(string $template): array
    {
        $templates = $this->getAvailableTemplates();
        return $templates[$template]["symbols"] ?? [];
    }

    /**
     * Template-Label holen
     */
    public function getTemplateLabel(string $template): string
    {
        $templates = $this->getAvailableTemplates();
        return $templates[$template]["label"] ?? $template;
    }

    /**
     * Template-Beschreibung holen
     */
    public function getTemplateDescription(string $template): string
    {
        $templates = $this->getAvailableTemplates();
        return $templates[$template]["description"] ?? "";
    }

    /**
     * Prüft ob Template existiert
     */
    public function templateExists(string $template): bool
    {
        $templates = $this->getAvailableTemplates();
        return isset($templates[$template]);
    }

    /**
     * Template-Statistiken
     */
    public function getTemplateStats(): array
    {
        $templates = $this->getAvailableTemplates();
        $stats = [];
        
        foreach ($templates as $key => $template) {
            $stats[$key] = [
                'label' => $template['label'],
                'symbol_count' => count($template['symbols']),
                'description' => $template['description']
            ];
        }
        
        return $stats;
    }
}