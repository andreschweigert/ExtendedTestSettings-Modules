<?php
/**
 * ExamChecker Config - Konfiguration für Prüfungs-Standards
 * Part of ExtendedTestSettings Plugin
 */

class ilExamCheckerConfig
{
    protected ilSetting $settings;

    public function __construct(string $namespace = 'examchecker')
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
     * Standard-Konfiguration für Prüfungstypen
     * (Optional - falls später mal anpassbare Standards gewünscht sind)
     */
    public function getExamStandards(string $exam_type): array
    {
        if ($exam_type === 'fern') {
            return [
                'required_settings' => [
                    'fragenpools_verwenden' => true,
                    'datenschutz' => true,
                    'online' => true,
                    'eigenstaendigkeitserklaerung' => true,
                    'passwort' => true,
                    'nur_ausgewaehlte_teilnehmer' => true,
                    'testdurchlaeufe_begrenzen' => true,
                    'bearbeitungsdauer' => true,
                    'pruefungsansicht' => true,
                    'automatisches_speichern' => true,
                    'direkte_rueckmeldung' => false,
                    'fragen_markieren' => true,
                    'bewertungssystem' => true,
                    'negative_punkte' => false,
                    'besten_durchlauf_bewerten' => true
                ],
                'manual_checks' => [
                    'start_und_ende',
                    'bearbeitungsdauer'
                ]
            ];
        } else { // praesenz
            return [
                'required_settings' => [
                    'fragenpools_verwenden' => true,
                    'datenschutz' => true,
                    'online' => true,
                    'passwort' => true,
                    'nur_ausgewaehlte_teilnehmer' => true,
                    'testdurchlaeufe_begrenzen' => true,
                    'pruefungsansicht' => true,
                    'automatisches_speichern' => true,
                    'direkte_rueckmeldung' => false,
                    'fragen_markieren' => true,
                    'weiterleitung' => true,
                    'bewertungssystem' => true,
                    'negative_punkte' => false,
                    'besten_durchlauf_bewerten' => true
                ],
                'manual_checks' => [
                    'start_und_ende',
                    'bearbeitungsdauer'
                ]
            ];
        }
    }

    /**
     * E-Mail-Textbausteine für Reports (aus deinen Python-Variablen)
     */
    public function getEmailBausteine(): array
    {
        return [
            'startText' => 'Liebe/r Herr/Frau XXX, 

beim stichpunktartigen Durchsehen der Einstellungen und Fragen Ihrer Prüfung am XXX habe ich ein paar Dinge festgestellt, auf die ich Sie gerne noch aufmerksam machen möchte. 

Auf Ebene des Tests: 

',
            'endText' => 'In den Fragen: 

- Auf den ersten Blick sind uns keine Unregelmäßigkeiten aufgefallen. Bitte klicken Sie den Test dennoch unbedingt eigenständig vor der anstehenden Prüfung durch und machen Sie sich selbst einen Eindruck über ihre getroffenen Einstellungen (in den Einstellungen anpassen und dann im Reiter „Info" den Test starten).

Aus technischer Sicht konnten wir auf den ersten Blick keine Schwierigkeiten feststellen.

Falls Sie offene Fragen haben, melden Sie sich gerne bei uns. Ich wünsche Ihnen schon einmal einen reibungslosen Prüfungsablauf!

Vielen Dank und beste Grüße',
            
            'startAllgemeineEinstellungen' => "In den Allgemeinen Einstellungen: 

",
            'startAuswertungEinstellungen' => "

Im Unterreiter Auswertung: 

",
            
            // Einstellungen Beschreibungen
            'fragenpools_verwenden' => 'Fragenpools verwenden',
            'datenschutz' => 'Stellen Sie bei Prüfungen immer ein, dass die Testergebnisse mit Namen ausgegeben werden. Anonyme Testergebnisse können im Nachhinein nicht mehr mit Namen angezeigt werden.',
            'zeitlichBegrenzteVerfügbarkeit' => 'Zeitlich begrenzte Verfügbarkeit',
            'online' => 'Der Test muss online geschaltet werden, damit die Prüflinge darauf zugreifen können.',
            'eigenstaendigkeitserklaerung' => 'Die Eigenständigkeitserklärung sollte bei Fernprüfungen aktiviert werden.',
            'passwort' => 'Setzen Sie unbedingt ein Testpasswort, um die Fairness beim Start der Prüfung sicherzustellen und einen weiteren Sicherheitsaspekt zu haben.',
            'bearbeitungsdauer' => 'Bei „Bearbeitungsdauer begrenzen" sollte die exakte Prüfungszeit in Minuten eingegeben werden.',
            'pruefungsansicht' => 'Prüfungsansicht',
            'automatisches_speichern' => 'Automatisches Speichern',
            'bewertungssystem' => '„Auch teilweise falsche Antworten erzielen Punkte" muss immer aktiviert sein. Es werden für insgesamt unvollständige oder teilweise falsche Antworten je nach Frage Punkte vergeben.',
            'besten_durchlauf_bewerten' => 'Die Option „Besten Testdurchlauf bewerten" muss immer aktiviert sein.'
        ];
    }
}