<?php

/**
 * GUI für Fragengruppierung
 * Erlaubt das Definieren von Fragegruppen und zeigt Ergebnisse je Gruppe.
 * Schreibt ausschließlich in Plugin-eigene Tabellen (examgrouping_*).
 * ILIAS-Kerntabellen werden nur gelesen.
 *
 * @ilCtrl_isCalledBy ilExamGroupingGUI: ilUIPluginRouterGUI
 */
class ilExamGroupingGUI
{
    private ilCtrl $ctrl;
    private ilGlobalTemplateInterface $tpl;
    private ilDBInterface $db;

    public function __construct()
    {
        global $DIC;
        $this->ctrl = $DIC->ctrl();
        $this->tpl  = $DIC->ui()->mainTemplate();
        $this->db   = $DIC->database();
    }

    public function executeCommand(): void
    {
        $post_cmd = $_POST['cmd'] ?? null;
        $cmd = is_array($post_cmd) ? (string) array_key_first($post_cmd) : ($_GET['cmd'] ?? 'show');
        $this->performCommand($cmd);
    }

    private function proxyLink(string $cmd, array $params = []): string
    {
        $this->ctrl->setParameterByClass('ilExtendedTestSettingsModuleProxyGUI', 'module_id', 'examgrouping');
        foreach ($params as $k => $v) {
            $this->ctrl->setParameterByClass('ilExtendedTestSettingsModuleProxyGUI', $k, (string) $v);
        }
        return $this->ctrl->getLinkTargetByClass(
            ['ilUIPluginRouterGUI', 'ilExtendedTestSettingsModuleProxyGUI'],
            $cmd
        );
    }

    private function proxyFormAction(string $cmd, array $params = []): string
    {
        $this->ctrl->setParameterByClass('ilExtendedTestSettingsModuleProxyGUI', 'module_id', 'examgrouping');
        foreach ($params as $k => $v) {
            $this->ctrl->setParameterByClass('ilExtendedTestSettingsModuleProxyGUI', $k, (string) $v);
        }
        return $this->ctrl->getFormActionByClass(
            ['ilUIPluginRouterGUI', 'ilExtendedTestSettingsModuleProxyGUI'],
            $cmd
        );
    }

    private function proxyRedirect(string $cmd, array $params = []): void
    {
        $this->ctrl->setParameterByClass('ilExtendedTestSettingsModuleProxyGUI', 'module_id', 'examgrouping');
        foreach ($params as $k => $v) {
            $this->ctrl->setParameterByClass('ilExtendedTestSettingsModuleProxyGUI', $k, (string) $v);
        }
        $this->ctrl->redirectByClass(
            ['ilUIPluginRouterGUI', 'ilExtendedTestSettingsModuleProxyGUI'],
            $cmd
        );
    }

    public function performCommand(string $cmd): void
    {
        global $DIC, $rbacsystem;

        $ref_id = (int) ($_GET['ref_id'] ?? $_POST['ref_id'] ?? 0);

        if (!$DIC->user()->getId() || $DIC->user()->isAnonymous()) {
            $DIC->ctrl()->redirectToURL('./login.php');
            return;
        }

        if ($ref_id <= 0) {
            $this->tpl->setOnScreenMessage('failure', 'Kein gültiger Test ausgewählt.');
            $this->tpl->printToStdout();
            return;
        }

        $is_admin = $rbacsystem->checkAccess('write', SYSTEM_FOLDER_ID);
        $has_write = $rbacsystem->checkAccess('write', $ref_id);

        if (!$is_admin && !$has_write) {
            $this->tpl->setOnScreenMessage('failure', 'Keine Berechtigung.');
            $DIC->ctrl()->redirectToURL("ilias.php?ref_id={$ref_id}&baseClass=ilRepositoryGUI");
            return;
        }

        switch ($cmd) {
            case 'addGroup':
                $this->addGroup($ref_id);
                break;
            case 'saveGroup':
                $this->saveGroup($ref_id);
                break;
            case 'deleteGroup':
                $this->deleteGroup($ref_id);
                break;
            case 'editGroup':
                $this->editGroup($ref_id);
                break;
            case 'saveGroupQuestions':
                $this->saveGroupQuestions($ref_id);
                break;
            case 'editGroupSettings':
                $this->editGroupSettings($ref_id);
                break;
            case 'saveGroupSettings':
                $this->saveGroupSettings($ref_id);
                break;
            case 'exportResults':
                $this->exportResults($ref_id);
                return; // exit() im Export, kein printToStdout nötig
            default:
                $this->show($ref_id);
                break;
        }

        $this->tpl->printToStdout();
    }

    // =========================================================================
    // Navigation
    // =========================================================================

    private function setupNavigation(int $ref_id): void
    {
        global $DIC, $ilTabs, $ilLocator;

        try {
            $test_title = ilObject::_lookupTitle(ilObject::_lookupObjectId($ref_id));

            $tabs = $ilTabs ?? ($DIC->offsetExists('ilTabs') ? $DIC['ilTabs'] : null);
            if ($tabs) {
                $back_url = "ilias.php?ref_id={$ref_id}&baseClass=ilRepositoryGUI";
                $tabs->setBackTarget('← ' . $test_title, $back_url);

                $tabs->addTab(
                    'exam_grouping',
                    'Fragengruppierung',
                    $this->proxyLink('show', ['ref_id' => $ref_id])
                );
                $tabs->activateTab('exam_grouping');
            }

            $this->tpl->setTitle('Fragengruppierung');
            $this->tpl->setDescription('Fragen zu Gruppen zusammenfassen und Ergebnisse auswerten');
        } catch (Exception $e) {
            // Navigation-Setup ist nicht kritisch
        }
    }

    // =========================================================================
    // Hauptansicht
    // =========================================================================

    private function show(int $ref_id): void
    {
        $obj_id = ilObject::_lookupObjectId($ref_id);

        $this->setupNavigation($ref_id);

        if (ilObject::_lookupType($obj_id) !== 'tst') {
            $this->tpl->setOnScreenMessage('failure', 'Das ausgewählte Objekt ist kein Test.');
            return;
        }

        if ($this->isRandomTest($obj_id)) {
            $this->tpl->setOnScreenMessage('info',
                'Fragengruppierung ist bei Tests mit zufälligen Fragenpools nicht verfügbar, '
                . 'da die Fragenzusammenstellung je Teilnehmer unterschiedlich ist.'
            );
            $this->tpl->setContent(
                '<p>Dieser Test verwendet einen zufälligen Fragenpool. '
                . 'Die Fragengruppierung ist nur bei Tests mit fixer Fragenliste verfügbar.</p>'
            );
            return;
        }

        $questions = $this->getTestQuestions($obj_id);
        $groups    = $this->getGroupsForTest($obj_id);

        if (empty($questions)) {
            $this->tpl->setOnScreenMessage('info', 'Dieser Test hat noch keine Fragen.');
        }

        $html  = $this->buildGroupsSection($ref_id, $obj_id, $groups, $questions);
        $html .= $this->buildResultsSection($ref_id, $obj_id, $groups, $questions);

        $this->tpl->setContent($html);
    }

    // =========================================================================
    // Gruppen-Übersicht
    // =========================================================================

    private function buildGroupsSection(int $ref_id, int $obj_id, array $groups, array $questions): string
    {
        $add_link = $this->proxyLink('addGroup', ['ref_id' => $ref_id]);

        $html  = '<div style="margin-bottom:2rem;">';
        $html .= '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">';
        $html .= '<h3 style="margin:0;">Fragengruppen</h3>';
        $html .= '<a href="' . htmlspecialchars($add_link) . '" class="btn btn-primary">+ Neue Gruppe</a>';
        $html .= '</div>';

        if (empty($groups)) {
            $html .= '<p class="text-muted">Noch keine Gruppen definiert. '
                   . 'Klicken Sie auf „Neue Gruppe", um eine Gruppe zu erstellen.</p>';
        } else {
            $html .= '<table class="table table-striped" style="width:100%;">';
            $html .= '<thead><tr>'
                   . '<th>Gruppe</th>'
                   . '<th>Enthaltene Fragen</th>'
                   . '<th>Max. Punkte</th>'
                   . '<th>Bestandens-Schwelle</th>'
                   . '<th>Aktionen</th>'
                   . '</tr></thead><tbody>';

            foreach ($groups as $group) {
                $group_qids = $this->getGroupQuestions($group['id']);
                $max_points = 0.0;
                $q_labels   = [];

                foreach ($group_qids as $qid) {
                    if (isset($questions[$qid])) {
                        $q_labels[]  = 'F' . $questions[$qid]['sequence'] . ': '
                                     . htmlspecialchars($questions[$qid]['title']);
                        $max_points += (float) $questions[$qid]['points'];
                    }
                }

                $edit_link     = $this->proxyLink('editGroup',         ['ref_id' => $ref_id, 'group_id' => $group['id']]);
                $settings_link = $this->proxyLink('editGroupSettings', ['ref_id' => $ref_id, 'group_id' => $group['id']]);
                $delete_link   = $this->proxyLink('deleteGroup',       ['ref_id' => $ref_id, 'group_id' => $group['id']]);

                $q_display = empty($q_labels)
                    ? '<em style="color:#999;">Keine Fragen zugeordnet</em>'
                    : implode('<br>', $q_labels);

                // Schwellenwert anzeigen
                $ttype = $group['threshold_type']  ?? 'percent';
                $tval  = $group['threshold_value'] ?? 50.0;
                if ($ttype === 'points') {
                    $threshold_display = number_format((float) $tval, 2) . ' Pkt';
                } else {
                    $threshold_display = number_format((float) $tval, 0) . '%';
                }

                $html .= '<tr>';
                $html .= '<td><strong>' . htmlspecialchars($group['name']) . '</strong></td>';
                $html .= '<td>' . $q_display . '</td>';
                $html .= '<td>' . number_format($max_points, 2) . ' Pkt</td>';
                $html .= '<td>' . htmlspecialchars($threshold_display) . '</td>';
                $html .= '<td style="white-space:nowrap;">';
                $html .= '<a href="' . htmlspecialchars($settings_link) . '" class="btn btn-sm btn-default">Einstellungen</a> ';
                $html .= '<a href="' . htmlspecialchars($edit_link) . '" class="btn btn-sm btn-default">Fragen</a> ';
                $html .= '<a href="' . htmlspecialchars($delete_link) . '" class="btn btn-sm btn-danger" '
                       . 'onclick="return confirm(\'Gruppe &quot;' . htmlspecialchars(addslashes($group['name'])) . '&quot; wirklich löschen?\');">'
                       . 'Löschen</a>';
                $html .= '</td>';
                $html .= '</tr>';
            }

            $html .= '</tbody></table>';
        }

        $html .= '</div>';
        return $html;
    }

    // =========================================================================
    // Ergebnisse-Ansicht
    // =========================================================================

    private function buildResultsSection(int $ref_id, int $obj_id, array $groups, array $questions): string
    {
        if (empty($groups)) {
            return '';
        }

        // Nur Gruppen mit zugeordneten Fragen berücksichtigen
        $active_groups = [];
        foreach ($groups as $group) {
            $qids = $this->getGroupQuestions($group['id']);
            if (!empty($qids)) {
                $active_groups[] = ['group' => $group, 'qids' => $qids];
            }
        }

        $html  = '<hr>';
        $html .= '<h3>Ergebnisse nach Gruppen</h3>';

        if (empty($active_groups)) {
            $html .= '<p class="text-muted">Ordnen Sie den Gruppen Fragen zu, um Ergebnisse anzuzeigen.</p>';
            return $html;
        }

        $passes = $this->getSubmittedPasses($obj_id);

        if (empty($passes)) {
            $html .= '<p class="text-muted">Noch keine abgeschlossenen Testdurchläufe vorhanden.</p>';
            return $html;
        }

        // Alle qids die irgendeiner Gruppe zugeordnet sind
        $all_grouped_qids = [];
        foreach ($active_groups as $ag) {
            foreach ($ag['qids'] as $qid) {
                $all_grouped_qids[$qid] = true;
            }
        }

        // Nicht zugeordnete Fragen (existieren im Test, aber in keiner Gruppe)
        $ungrouped_qids = [];
        $ungrouped_max  = 0.0;
        foreach ($questions as $qid => $q) {
            if (!isset($all_grouped_qids[$qid])) {
                $ungrouped_qids[] = $qid;
                $ungrouped_max   += (float) $q['points'];
            }
        }
        $has_ungrouped = !empty($ungrouped_qids);

        // Export-Button
        $export_link = $this->proxyLink('exportResults', ['ref_id' => $ref_id]);
        $html .= '<div style="text-align:right;margin-bottom:0.5rem;">'
               . '<a href="' . htmlspecialchars($export_link) . '" class="btn btn-default btn-sm">&#11015; CSV exportieren</a>'
               . '</div>';

        // Tabellenkopf
        $html .= '<div style="overflow-x:auto;">';
        $html .= '<table class="table table-sm table-bordered">';
        $html .= '<thead><tr>';
        $html .= '<th>Teilnehmer</th>';
        $html .= '<th style="white-space:nowrap;">Durchlauf</th>';

        // Max-Punkte je aktiver Gruppe vorberechnen
        $group_max = [];
        foreach ($active_groups as $ag) {
            $max_pts = 0.0;
            foreach ($ag['qids'] as $qid) {
                if (isset($questions[$qid])) {
                    $max_pts += (float) $questions[$qid]['points'];
                }
            }
            $group_max[$ag['group']['id']] = $max_pts;

            $ttype = $ag['group']['threshold_type']  ?? 'percent';
            $tval  = (float) ($ag['group']['threshold_value'] ?? 50);
            $threshold_label = $ttype === 'points'
                ? 'ab ' . number_format($tval, 2) . ' Pkt'
                : 'ab ' . number_format($tval, 0) . '%';

            $html .= '<th style="white-space:nowrap;">'
                   . htmlspecialchars($ag['group']['name'])
                   . '<br><small style="font-weight:normal;color:#666;">max. '
                   . number_format($max_pts, 2) . ' Pkt</small>'
                   . '<br><small style="color:#555;">✓ ' . $threshold_label . '</small>'
                   . '</th>';
        }

        // Spalte für nicht zugeordnete Fragen (falls vorhanden)
        if ($has_ungrouped) {
            $html .= '<th style="white-space:nowrap;color:#856404;background:#fff3cd;">'
                   . 'Nicht zugeordnet'
                   . '<br><small style="font-weight:normal;">max. ' . number_format($ungrouped_max, 2) . ' Pkt</small>'
                   . '<br><small>' . count($ungrouped_qids) . ' Frage(n)</small>'
                   . '</th>';
        }

        $html .= '<th>Gesamt (alle Fragen)</th>';
        $html .= '</tr></thead><tbody>';

        // Maximalpunkte des Tests: jede Frage einmal, unabhängig von Gruppen
        $test_total_max = 0.0;
        foreach ($questions as $q) {
            $test_total_max += (float) $q['points'];
        }

        // Passes nach Teilnehmer (active_id) gruppieren, Reihenfolge beibehalten
        $by_participant = [];
        foreach ($passes as $pass) {
            $aid = $pass['active_id'];
            if (!isset($by_participant[$aid])) {
                $by_participant[$aid] = [
                    'firstname' => $pass['firstname'],
                    'lastname'  => $pass['lastname'],
                    'login'     => $pass['login'],
                    'passes'    => [],
                ];
            }
            $by_participant[$aid]['passes'][] = $pass;
        }

        // Akkumulatoren für Ø-Zeile — über letzte Passes je Teilnehmer
        $summary_reached       = array_fill_keys(array_column(array_column($active_groups, 'group'), 'id'), 0.0);
        $summary_ungrouped     = 0.0;
        $summary_total_reached = 0.0;
        $participant_count     = count($by_participant);

        foreach ($by_participant as $aid => $participant) {
            $participant_passes = $participant['passes']; // aufsteigend nach pass-Nr
            $pass_count_local   = count($participant_passes);
            $name  = trim(($participant['firstname'] ?? '') . ' ' . ($participant['lastname'] ?? ''));
            $login = $participant['login'] ?? 'Anonym';

            // Vorab Gesamtpunkte je Pass berechnen (für Trendpfeil)
            $totals_per_pass = [];
            foreach ($participant_passes as $p) {
                $qr = $this->getQuestionResults($p['active_id'], $p['pass']);
                $t  = 0.0;
                foreach ($questions as $qid => $q) {
                    $t += (float) ($qr[$qid] ?? 0.0);
                }
                $totals_per_pass[$p['pass']] = $t;
            }

            foreach ($participant_passes as $idx => $pass) {
                $q_results  = $this->getQuestionResults($pass['active_id'], $pass['pass']);
                $pass_nr    = $pass['pass'] + 1; // ILIAS zählt ab 0
                $is_first   = ($idx === 0);
                $is_last    = ($idx === $pass_count_local - 1);

                // Zeilenhintergrund: erster Durchlauf neutral, weitere leicht eingerückt
                $row_bg     = $is_first ? '' : 'background:#fafafa;';
                $html      .= '<tr style="' . $row_bg . '">';

                // Teilnehmer-Zelle: nur beim ersten Durchlauf Name + Login,
                // danach nur Pass-Info + Zeitstempel
                if ($is_first) {
                    $html .= '<td rowspan="' . $pass_count_local . '" style="vertical-align:top;border-right:2px solid #dee2e6;">'
                           . '<strong>' . htmlspecialchars($name ?: $login) . '</strong>'
                           . '<br><small style="color:#999;">' . htmlspecialchars($login) . '</small>'
                           . ($pass_count_local > 1
                               ? '<br><small style="color:#6c757d;margin-top:4px;display:block;">'
                                 . $pass_count_local . ' Durchläufe</small>'
                               : '')
                           . '</td>';
                }

                // Pass-Nummer + Datum
                $date_str = '';
                if (!empty($pass['submitted_at'])) {
                    $ts = strtotime($pass['submitted_at']);
                    if ($ts) {
                        $date_str = date('d.m.Y H:i', $ts);
                    }
                }

                // Trendpfeil gegenüber vorherigem Durchlauf
                $trend = '';
                if ($idx > 0) {
                    $prev_total = $totals_per_pass[$participant_passes[$idx - 1]['pass']];
                    $curr_total = $totals_per_pass[$pass['pass']];
                    if ($curr_total > $prev_total) {
                        $trend = ' <span style="color:#28a745;font-weight:bold;" title="Besser als vorheriger Durchlauf">▲</span>';
                    } elseif ($curr_total < $prev_total) {
                        $trend = ' <span style="color:#dc3545;font-weight:bold;" title="Schlechter als vorheriger Durchlauf">▼</span>';
                    } else {
                        $trend = ' <span style="color:#6c757d;" title="Gleich wie vorheriger Durchlauf">→</span>';
                    }
                }

                // Pass-Info-Zelle (erste Datenspalte)
                $html .= '<td style="white-space:nowrap;color:#555;font-size:0.85em;vertical-align:top;">'
                       . '<strong>Durchlauf ' . $pass_nr . '</strong>' . $trend
                       . ($date_str ? '<br><small>' . $date_str . '</small>' : '')
                       . '</td>';

                // Punkte je Gruppe
                foreach ($active_groups as $ag) {
                    $gid     = $ag['group']['id'];
                    $reached = 0.0;
                    $max     = $group_max[$gid];

                    foreach ($ag['qids'] as $qid) {
                        if (isset($questions[$qid])) {
                            $reached += (float) ($q_results[$qid] ?? 0.0);
                        }
                    }

                    // Für Ø nur letzten Durchlauf zählen
                    if ($is_last) {
                        $summary_reached[$gid] += $reached;
                    }

                    $pct    = $max > 0 ? ($reached / $max) * 100 : 0;
                    $ttype  = $ag['group']['threshold_type']  ?? 'percent';
                    $tval   = (float) ($ag['group']['threshold_value'] ?? 50);
                    $passed = $ttype === 'points' ? $reached >= $tval : $pct >= $tval;
                    $bg     = $passed ? '#d4edda' : '#f8d7da';
                    $label  = $passed ? '✓' : '✗';

                    $html .= '<td style="background:' . $bg . ';white-space:nowrap;vertical-align:top;">'
                           . '<strong>' . number_format($reached, 2) . ' Pkt</strong>'
                           . '<br><small>von ' . number_format($max, 2) . ' Pkt'
                           . ' (' . number_format($pct, 1) . '%)</small>'
                           . '<br><small>' . $label . ($passed ? ' Bestanden' : ' Nicht best.') . '</small>'
                           . '</td>';
                }

                // Nicht zugeordnete Fragen
                if ($has_ungrouped) {
                    $ug_reached = 0.0;
                    foreach ($ungrouped_qids as $qid) {
                        $ug_reached += (float) ($q_results[$qid] ?? 0.0);
                    }
                    if ($is_last) {
                        $summary_ungrouped += $ug_reached;
                    }
                    $ug_pct = $ungrouped_max > 0 ? ($ug_reached / $ungrouped_max) * 100 : 0;
                    $html .= '<td style="background:#fff3cd;white-space:nowrap;vertical-align:top;">'
                           . number_format($ug_reached, 2) . ' Pkt'
                           . '<br><small>von ' . number_format($ungrouped_max, 2) . ' Pkt'
                           . ' (' . number_format($ug_pct, 1) . '%)</small>'
                           . '</td>';
                }

                // Gesamt
                $total_reached = $totals_per_pass[$pass['pass']];
                if ($is_last) {
                    $summary_total_reached += $total_reached;
                }
                $total_pct = $test_total_max > 0 ? ($total_reached / $test_total_max) * 100 : 0;
                $html .= '<td style="white-space:nowrap;font-weight:bold;vertical-align:top;">'
                       . number_format($total_reached, 2) . ' Pkt'
                       . '<br><small>von ' . number_format($test_total_max, 2) . ' Pkt'
                       . ' (' . number_format($total_pct, 1) . '%)</small>'
                       . '</td>';

                $html .= '</tr>';
            }

            // Trennlinie nach jedem Teilnehmer (außer dem letzten)
            if ($aid !== array_key_last($by_participant)) {
                $col_count = 2 + count($active_groups) + ($has_ungrouped ? 1 : 0) + 1;
                $html .= '<tr><td colspan="' . $col_count . '" style="padding:0;border-top:2px solid #dee2e6;"></td></tr>';
            }
        }

        // Zusammenfassungszeile (Ø letzter Durchlauf je Teilnehmer)
        if ($participant_count > 0) {
            $col_span_name = 2; // Name + Durchlauf-Zelle
            $html .= '<tr style="background:#f8f9fa;font-style:italic;border-top:2px solid #adb5bd;">';
            $html .= '<td colspan="' . $col_span_name . '"><strong>Ø letzter Durchlauf</strong>'
                   . '<br><small>(' . $participant_count . ' Teilnehmer)</small></td>';

            foreach ($active_groups as $ag) {
                $gid     = $ag['group']['id'];
                $max     = $group_max[$gid];
                $avg     = $summary_reached[$gid] / $participant_count;
                $avg_pct = $max > 0 ? ($avg / $max) * 100 : 0;
                $html .= '<td style="white-space:nowrap;">'
                       . number_format($avg, 2) . ' Pkt'
                       . '<br><small>von ' . number_format($max, 2) . ' Pkt'
                       . ' (' . number_format($avg_pct, 1) . '%)</small>'
                       . '</td>';
            }

            if ($has_ungrouped) {
                $ug_avg     = $summary_ungrouped / $participant_count;
                $ug_avg_pct = $ungrouped_max > 0 ? ($ug_avg / $ungrouped_max) * 100 : 0;
                $html .= '<td style="background:#fff3cd;white-space:nowrap;">'
                       . number_format($ug_avg, 2) . ' Pkt'
                       . '<br><small>von ' . number_format($ungrouped_max, 2) . ' Pkt'
                       . ' (' . number_format($ug_avg_pct, 1) . '%)</small>'
                       . '</td>';
            }

            $total_avg     = $summary_total_reached / $participant_count;
            $total_avg_pct = $test_total_max > 0 ? ($total_avg / $test_total_max) * 100 : 0;
            $html .= '<td style="white-space:nowrap;font-weight:bold;">'
                   . number_format($total_avg, 2) . ' Pkt'
                   . '<br><small>von ' . number_format($test_total_max, 2) . ' Pkt'
                   . ' (' . number_format($total_avg_pct, 1) . '%)</small>'
                   . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        $html .= '</div>';

        return $html;
    }

    // =========================================================================
    // Gruppe hinzufügen
    // =========================================================================

    private function addGroup(int $ref_id): void
    {
        $this->setupNavigation($ref_id);

        $form = new ilPropertyFormGUI();
        $form->setFormAction($this->proxyFormAction('saveGroup', ['ref_id' => $ref_id]));
        $form->setTitle('Neue Fragengruppe erstellen');

        $name_input = new ilTextInputGUI('Gruppenname', 'group_name');
        $name_input->setRequired(true);
        $name_input->setMaxLength(255);
        $form->addItem($name_input);

        $ref_hidden = new ilHiddenInputGUI('ref_id');
        $ref_hidden->setValue((string) $ref_id);
        $form->addItem($ref_hidden);

        $form->addCommandButton('saveGroup', 'Gruppe erstellen');
        $form->addCommandButton('show', 'Abbrechen');

        $this->tpl->setContent($form->getHTML());
    }

    private function saveGroup(int $ref_id): void
    {
        $obj_id = ilObject::_lookupObjectId($ref_id);
        $name   = trim($_POST['group_name'] ?? '');

        if ($name === '') {
            $this->tpl->setOnScreenMessage('failure', 'Bitte geben Sie einen Gruppennamen ein.');
            $this->addGroup($ref_id);
            return;
        }

        $result   = $this->db->queryF(
            "SELECT MAX(position) AS max_pos FROM examgrouping_groups WHERE test_id = %s",
            ['integer'],
            [$obj_id]
        );
        $row      = $this->db->fetchAssoc($result);
        $position = (int) ($row['max_pos'] ?? 0) + 1;

        $new_id = $this->db->nextId('examgrouping_groups');
        $this->db->insert('examgrouping_groups', [
            'id'         => ['integer',   $new_id],
            'test_id'    => ['integer',   $obj_id],
            'name'       => ['text',      $name],
            'position'   => ['integer',   $position],
            'created_at' => ['timestamp', date('Y-m-d H:i:s')],
        ]);

        $this->tpl->setOnScreenMessage('success', 'Gruppe „' . htmlspecialchars($name) . '" wurde erstellt.');
        $this->proxyRedirect('show', ['ref_id' => $ref_id]);
    }

    // =========================================================================
    // Gruppe löschen
    // =========================================================================

    private function deleteGroup(int $ref_id): void
    {
        $group_id = (int) ($_GET['group_id'] ?? 0);
        $obj_id   = ilObject::_lookupObjectId($ref_id);

        if ($group_id > 0) {
            // Sicherheitsprüfung: Gruppe muss zu diesem Test gehören
            $result = $this->db->queryF(
                "SELECT id, name FROM examgrouping_groups WHERE id = %s AND test_id = %s",
                ['integer', 'integer'],
                [$group_id, $obj_id]
            );
            $group = $this->db->fetchAssoc($result);

            if ($group) {
                $this->db->manipulate(
                    "DELETE FROM examgrouping_items WHERE group_id = "
                    . $this->db->quote($group_id, 'integer')
                );
                $this->db->manipulate(
                    "DELETE FROM examgrouping_groups WHERE id = "
                    . $this->db->quote($group_id, 'integer')
                );
                $this->tpl->setOnScreenMessage(
                    'success',
                    'Gruppe „' . htmlspecialchars($group['name']) . '" wurde gelöscht.'
                );
            }
        }

        $this->proxyRedirect('show', ['ref_id' => $ref_id]);
    }

    // =========================================================================
    // Gruppen-Einstellungen (Name + Schwellenwert)
    // =========================================================================

    private function editGroupSettings(int $ref_id): void
    {
        $group_id = (int) ($_GET['group_id'] ?? 0);
        $obj_id   = ilObject::_lookupObjectId($ref_id);

        if ($group_id <= 0) {
            $this->proxyRedirect('show', ['ref_id' => $ref_id]);
            return;
        }

        $result = $this->db->queryF(
            "SELECT id, name, threshold_type, threshold_value
             FROM examgrouping_groups WHERE id = %s AND test_id = %s",
            ['integer', 'integer'],
            [$group_id, $obj_id]
        );
        $group = $this->db->fetchAssoc($result);

        if (!$group) {
            $this->tpl->setOnScreenMessage('failure', 'Gruppe nicht gefunden.');
            $this->proxyRedirect('show', ['ref_id' => $ref_id]);
            return;
        }

        $this->setupNavigation($ref_id);

        $form = new ilPropertyFormGUI();
        $form->setFormAction($this->proxyFormAction('saveGroupSettings', ['ref_id' => $ref_id, 'group_id' => $group_id]));
        $form->setTitle('Einstellungen: „' . htmlspecialchars($group['name']) . '"');

        // Gruppenname
        $name_input = new ilTextInputGUI('Gruppenname', 'group_name');
        $name_input->setRequired(true);
        $name_input->setMaxLength(255);
        $name_input->setValue($group['name']);
        $form->addItem($name_input);

        // Schwellenwert-Typ
        $section = new ilFormSectionHeaderGUI();
        $section->setTitle('Bestandens-Schwelle');
        $form->addItem($section);

        $type_select = new ilSelectInputGUI('Typ', 'threshold_type');
        $type_select->setOptions([
            'percent' => 'Prozent (%)',
            'points'  => 'Punkte (Pkt)',
        ]);
        $type_select->setValue($group['threshold_type'] ?? 'percent');
        $form->addItem($type_select);

        $val_input = new ilNumberInputGUI('Schwellenwert', 'threshold_value');
        $val_input->setRequired(true);
        $val_input->setMinValue(0);
        $val_input->setDecimals(2);
        $val_input->setValue((string) ($group['threshold_value'] ?? 50));
        $val_input->setInfo(
            'Bei Typ "Prozent": Wert zwischen 0 und 100 (z.B. 50 = mind. 50% der Gruppenpunkte).<br>'
            . 'Bei Typ "Punkte": absolute Mindestpunktzahl in dieser Gruppe.'
        );
        $form->addItem($val_input);

        $ref_hidden = new ilHiddenInputGUI('ref_id');
        $ref_hidden->setValue((string) $ref_id);
        $form->addItem($ref_hidden);

        $gid_hidden = new ilHiddenInputGUI('group_id');
        $gid_hidden->setValue((string) $group_id);
        $form->addItem($gid_hidden);

        $form->addCommandButton('saveGroupSettings', 'Speichern');
        $form->addCommandButton('show', 'Abbrechen');

        $this->tpl->setContent($form->getHTML());
    }

    private function saveGroupSettings(int $ref_id): void
    {
        $group_id = (int) ($_POST['group_id'] ?? 0);
        $obj_id   = ilObject::_lookupObjectId($ref_id);

        if ($group_id <= 0) {
            $this->proxyRedirect('show', ['ref_id' => $ref_id]);
            return;
        }

        // Sicherheitsprüfung
        $result = $this->db->queryF(
            "SELECT id FROM examgrouping_groups WHERE id = %s AND test_id = %s",
            ['integer', 'integer'],
            [$group_id, $obj_id]
        );
        if ($this->db->numRows($result) === 0) {
            $this->tpl->setOnScreenMessage('failure', 'Gruppe nicht gefunden.');
            $this->proxyRedirect('show', ['ref_id' => $ref_id]);
            return;
        }

        $name       = trim($_POST['group_name']       ?? '');
        $ttype      = in_array($_POST['threshold_type'] ?? '', ['percent', 'points'])
                      ? $_POST['threshold_type']
                      : 'percent';
        $tval       = max(0.0, (float) str_replace(',', '.', $_POST['threshold_value'] ?? '50'));

        if ($name === '') {
            $this->tpl->setOnScreenMessage('failure', 'Gruppenname darf nicht leer sein.');
            $this->proxyRedirect('editGroupSettings', ['ref_id' => $ref_id, 'group_id' => $group_id]);
            return;
        }

        $this->db->update(
            'examgrouping_groups',
            [
                'name'            => ['text',    $name],
                'threshold_type'  => ['text',    $ttype],
                'threshold_value' => ['float',   $tval],
            ],
            ['id' => ['integer', $group_id]]
        );

        $this->tpl->setOnScreenMessage('success', 'Einstellungen gespeichert.');
        $this->proxyRedirect('show', ['ref_id' => $ref_id]);
    }

    // =========================================================================
    // Fragen einer Gruppe bearbeiten
    // =========================================================================

    private function editGroup(int $ref_id): void
    {
        $group_id = (int) ($_GET['group_id'] ?? 0);
        $obj_id   = ilObject::_lookupObjectId($ref_id);

        if ($group_id <= 0) {
            $this->proxyRedirect('show', ['ref_id' => $ref_id]);
            return;
        }

        $result = $this->db->queryF(
            "SELECT id, name FROM examgrouping_groups WHERE id = %s AND test_id = %s",
            ['integer', 'integer'],
            [$group_id, $obj_id]
        );
        $group = $this->db->fetchAssoc($result);

        if (!$group) {
            $this->tpl->setOnScreenMessage('failure', 'Gruppe nicht gefunden.');
            $this->proxyRedirect('show', ['ref_id' => $ref_id]);
            return;
        }

        $this->setupNavigation($ref_id);

        $questions    = $this->getTestQuestions($obj_id);
        $assigned     = $this->getGroupQuestions($group_id);
        $assigned_set = array_flip($assigned);

        $form = new ilPropertyFormGUI();
        $form->setFormAction($this->proxyFormAction('saveGroupQuestions', ['ref_id' => $ref_id, 'group_id' => $group_id]));
        $form->setTitle('Fragen zuordnen: „' . htmlspecialchars($group['name']) . '"');

        if (empty($questions)) {
            $info = new ilCustomInputGUI('');
            $info->setHtml('<p style="color:#999;">Dieser Test hat noch keine Fragen.</p>');
            $form->addItem($info);
        } else {
            $section = new ilFormSectionHeaderGUI();
            $section->setTitle('Fragen auswählen (mehrere möglich)');
            $form->addItem($section);

            foreach ($questions as $qid => $q) {
                $cb = new ilCheckboxInputGUI(
                    'F' . $q['sequence'] . ': ' . htmlspecialchars($q['title'])
                    . ' (' . number_format((float) $q['points'], 2) . ' Pkt)',
                    'question_' . $qid
                );
                $cb->setChecked(isset($assigned_set[$qid]));
                $form->addItem($cb);
            }
        }

        $ref_hidden = new ilHiddenInputGUI('ref_id');
        $ref_hidden->setValue((string) $ref_id);
        $form->addItem($ref_hidden);

        $gid_hidden = new ilHiddenInputGUI('group_id');
        $gid_hidden->setValue((string) $group_id);
        $form->addItem($gid_hidden);

        $form->addCommandButton('saveGroupQuestions', 'Speichern');
        $form->addCommandButton('show', 'Abbrechen');

        $this->tpl->setContent($form->getHTML());
    }

    private function saveGroupQuestions(int $ref_id): void
    {
        $group_id = (int) ($_POST['group_id'] ?? 0);
        $obj_id   = ilObject::_lookupObjectId($ref_id);

        if ($group_id <= 0) {
            $this->proxyRedirect('show', ['ref_id' => $ref_id]);
            return;
        }

        // Sicherheitsprüfung: Gruppe muss zu diesem Test gehören
        $result = $this->db->queryF(
            "SELECT id FROM examgrouping_groups WHERE id = %s AND test_id = %s",
            ['integer', 'integer'],
            [$group_id, $obj_id]
        );
        if ($this->db->numRows($result) === 0) {
            $this->tpl->setOnScreenMessage('failure', 'Gruppe nicht gefunden.');
            $this->proxyRedirect('show', ['ref_id' => $ref_id]);
            return;
        }

        $questions = $this->getTestQuestions($obj_id);

        // Alte Zuordnungen löschen
        $this->db->manipulate(
            "DELETE FROM examgrouping_items WHERE group_id = "
            . $this->db->quote($group_id, 'integer')
        );

        // Neue Zuordnungen einfügen
        $count = 0;
        foreach ($questions as $qid => $q) {
            if (!empty($_POST['question_' . $qid])) {
                $new_id = $this->db->nextId('examgrouping_items');
                $this->db->insert('examgrouping_items', [
                    'id'          => ['integer', $new_id],
                    'group_id'    => ['integer', $group_id],
                    'question_id' => ['integer', $qid],
                ]);
                $count++;
            }
        }

        $this->tpl->setOnScreenMessage('success', $count . ' Fragen der Gruppe zugeordnet.');
        $this->proxyRedirect('show', ['ref_id' => $ref_id]);
    }

    // =========================================================================
    // CSV-Export
    // =========================================================================

    private function exportResults(int $ref_id): void
    {
        $obj_id = ilObject::_lookupObjectId($ref_id);

        if (ilObject::_lookupType($obj_id) !== 'tst' || $this->isRandomTest($obj_id)) {
            $this->tpl->setOnScreenMessage('failure', 'Export nicht möglich.');
            $this->proxyRedirect('show', ['ref_id' => $ref_id]);
            return;
        }

        $questions     = $this->getTestQuestions($obj_id);
        $groups        = $this->getGroupsForTest($obj_id);
        $active_groups = [];
        foreach ($groups as $group) {
            $qids = $this->getGroupQuestions($group['id']);
            if (!empty($qids)) {
                $active_groups[] = ['group' => $group, 'qids' => $qids];
            }
        }

        // Nicht zugeordnete Fragen
        $all_grouped_qids = [];
        foreach ($active_groups as $ag) {
            foreach ($ag['qids'] as $qid) {
                $all_grouped_qids[$qid] = true;
            }
        }
        $ungrouped_qids = [];
        $ungrouped_max  = 0.0;
        foreach ($questions as $qid => $q) {
            if (!isset($all_grouped_qids[$qid])) {
                $ungrouped_qids[] = $qid;
                $ungrouped_max   += (float) $q['points'];
            }
        }
        $has_ungrouped = !empty($ungrouped_qids);

        // Max-Punkte je Gruppe
        $group_max = [];
        foreach ($active_groups as $ag) {
            $max_pts = 0.0;
            foreach ($ag['qids'] as $qid) {
                if (isset($questions[$qid])) {
                    $max_pts += (float) $questions[$qid]['points'];
                }
            }
            $group_max[$ag['group']['id']] = $max_pts;
        }

        $passes     = $this->getSubmittedPasses($obj_id);
        $test_title = ilObject::_lookupTitle($obj_id);

        // Maximalpunkte des gesamten Tests (jede Frage einmal, unabhängig von Gruppen)
        $test_total_max = 0.0;
        foreach ($questions as $q) {
            $test_total_max += (float) $q['points'];
        }

        // ── CSV-Header aufbauen ──────────────────────────────────────────────
        // Struktur je Gruppe:
        //   [Gruppe] F1: Titel (Pkt) | F1 Max | ... | Gruppe Gesamt | Max | % | Bestanden
        // Danach "Nicht zugeordnet" (falls vorhanden), dann Gesamt-Test-Spalten.

        $header = ['Name', 'Login', 'Durchlauf', 'Datum'];

        foreach ($active_groups as $ag) {
            $gname = $ag['group']['name'];
            // Einzelfragen dieser Gruppe (sortiert nach Sequence)
            $ag_questions = [];
            foreach ($ag['qids'] as $qid) {
                if (isset($questions[$qid])) {
                    $ag_questions[$qid] = $questions[$qid];
                }
            }
            uasort($ag_questions, fn($a, $b) => $a['sequence'] <=> $b['sequence']);

            foreach ($ag_questions as $qid => $q) {
                $label    = 'F' . $q['sequence'] . ': ' . $q['title'];
                $header[] = '[' . $gname . '] ' . $label . ' (Pkt)';
                $header[] = '[' . $gname . '] ' . $label . ' (Max ' . number_format((float)$q['points'], 2, ',', '') . ')';
            }
            // Gruppen-Summe
            $header[] = '[' . $gname . '] Gesamt (Pkt)';
            $header[] = '[' . $gname . '] Gesamt Max (' . number_format($group_max[$ag['group']['id']], 2, ',', '') . ')';
            $header[] = '[' . $gname . '] Gesamt (%)';
            $header[] = '[' . $gname . '] Bestanden';
        }

        if ($has_ungrouped) {
            $ug_questions = [];
            foreach ($ungrouped_qids as $qid) {
                if (isset($questions[$qid])) {
                    $ug_questions[$qid] = $questions[$qid];
                }
            }
            uasort($ug_questions, fn($a, $b) => $a['sequence'] <=> $b['sequence']);

            foreach ($ug_questions as $qid => $q) {
                $label    = 'F' . $q['sequence'] . ': ' . $q['title'];
                $header[] = '[Nicht zugeordnet] ' . $label . ' (Pkt)';
                $header[] = '[Nicht zugeordnet] ' . $label . ' (Max ' . number_format((float)$q['points'], 2, ',', '') . ')';
            }
            $header[] = '[Nicht zugeordnet] Gesamt (Pkt)';
            $header[] = '[Nicht zugeordnet] Gesamt Max (' . number_format($ungrouped_max, 2, ',', '') . ')';
            $header[] = '[Nicht zugeordnet] Gesamt (%)';
        }

        $header[] = 'Gesamt Test (Pkt)';
        $header[] = 'Gesamt Test Max';
        $header[] = 'Gesamt Test (%)';

        // ── HTTP-Download-Header ─────────────────────────────────────────────
        $safe_title = preg_replace('/[^a-zA-Z0-9_-]/', '_', $test_title);
        $filename   = 'fragengruppierung_' . $safe_title . '_' . date('Ymd_His') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF"); // UTF-8 BOM für Excel
        fputcsv($out, $header, ';');

        // ── Datenzeilen ──────────────────────────────────────────────────────
        foreach ($passes as $pass) {
            $q_results = $this->getQuestionResults($pass['active_id'], $pass['pass']);

            $name     = trim(($pass['firstname'] ?? '') . ' ' . ($pass['lastname'] ?? ''));
            $login    = $pass['login'] ?? '';
            $pass_nr  = $pass['pass'] + 1;
            $date_str = '';
            if (!empty($pass['submitted_at'])) {
                $ts = strtotime($pass['submitted_at']);
                if ($ts) {
                    $date_str = date('d.m.Y H:i', $ts);
                }
            }
            $row = [$name ?: $login, $login, $pass_nr, $date_str];

            foreach ($active_groups as $ag) {
                $gid      = $ag['group']['id'];
                $max      = $group_max[$gid];
                $reached  = 0.0;

                // Einzelfragen (gleiche Sortierung wie Header)
                $ag_questions = [];
                foreach ($ag['qids'] as $qid) {
                    if (isset($questions[$qid])) {
                        $ag_questions[$qid] = $questions[$qid];
                    }
                }
                uasort($ag_questions, fn($a, $b) => $a['sequence'] <=> $b['sequence']);

                foreach ($ag_questions as $qid => $q) {
                    $q_reached = (float) ($q_results[$qid] ?? 0.0);
                    $reached  += $q_reached;
                    $row[]     = number_format($q_reached,      2, ',', '');
                    $row[]     = number_format((float)$q['points'], 2, ',', '');
                }

                $pct    = $max > 0 ? ($reached / $max) * 100 : 0;
                $ttype  = $ag['group']['threshold_type']  ?? 'percent';
                $tval   = (float) ($ag['group']['threshold_value'] ?? 50);
                $passed = $ttype === 'points' ? $reached >= $tval : $pct >= $tval;

                $row[] = number_format($reached, 2, ',', '');
                $row[] = number_format($max,     2, ',', '');
                $row[] = number_format($pct,     1, ',', '');
                $row[] = $passed ? 'Ja' : 'Nein';
            }

            if ($has_ungrouped) {
                $ug_questions = [];
                foreach ($ungrouped_qids as $qid) {
                    if (isset($questions[$qid])) {
                        $ug_questions[$qid] = $questions[$qid];
                    }
                }
                uasort($ug_questions, fn($a, $b) => $a['sequence'] <=> $b['sequence']);

                $ug_reached = 0.0;
                foreach ($ug_questions as $qid => $q) {
                    $q_reached   = (float) ($q_results[$qid] ?? 0.0);
                    $ug_reached += $q_reached;
                    $row[]       = number_format($q_reached,         2, ',', '');
                    $row[]       = number_format((float)$q['points'], 2, ',', '');
                }

                $ug_pct = $ungrouped_max > 0 ? ($ug_reached / $ungrouped_max) * 100 : 0;

                $row[] = number_format($ug_reached,    2, ',', '');
                $row[] = number_format($ungrouped_max, 2, ',', '');
                $row[] = number_format($ug_pct,        1, ',', '');
            }

            // Gesamt: jede Frage einmal zählen (nicht Gruppen-Summen, da Fragen in mehreren Gruppen sein können)
            $total_reached = 0.0;
            foreach ($questions as $qid => $q) {
                $total_reached += (float) ($q_results[$qid] ?? 0.0);
            }
            $total_pct = $test_total_max > 0 ? ($total_reached / $test_total_max) * 100 : 0;
            $row[] = number_format($total_reached,  2, ',', '');
            $row[] = number_format($test_total_max, 2, ',', '');
            $row[] = number_format($total_pct,      1, ',', '');

            fputcsv($out, $row, ';');
        }

        fclose($out);
        exit();
    }

    // =========================================================================
    // Hilfsmethoden (nur lesend auf ILIAS-Kerntabellen)
    // =========================================================================

    private function isRandomTest(int $obj_id): bool
    {
        $result = $this->db->queryF(
            "SELECT question_set_type FROM tst_tests WHERE obj_fi = %s",
            ['integer'],
            [$obj_id]
        );
        if ($row = $this->db->fetchAssoc($result)) {
            return $row['question_set_type'] === 'RANDOM_QUEST_SET';
        }
        return false;
    }

    /**
     * Gibt Fragen des Tests zurück, indiziert nach question_fi (= question_id in qpl_questions).
     * Liest nur tst_test_question + qpl_questions.
     */
    private function getTestQuestions(int $obj_id): array
    {
        // obj_id → test_id (interner PK in tst_tests)
        $result = $this->db->queryF(
            "SELECT test_id FROM tst_tests WHERE obj_fi = %s",
            ['integer'],
            [$obj_id]
        );
        $row = $this->db->fetchAssoc($result);
        if (!$row) {
            return [];
        }
        $test_id = (int) $row['test_id'];

        $result = $this->db->queryF(
            "SELECT ttq.question_fi, ttq.sequence, q.title, q.points
             FROM tst_test_question ttq
             JOIN qpl_questions q ON q.question_id = ttq.question_fi
             WHERE ttq.test_fi = %s
             ORDER BY ttq.sequence",
            ['integer'],
            [$test_id]
        );

        $questions = [];
        while ($row = $this->db->fetchAssoc($result)) {
            $questions[(int) $row['question_fi']] = [
                'sequence' => (int)   $row['sequence'],
                'title'    =>         $row['title'],
                'points'   => (float) $row['points'],
            ];
        }

        return $questions;
    }

    private function getGroupsForTest(int $obj_id): array
    {
        // Prüfen ob Threshold-Spalten bereits per DB-Migration vorhanden sind
        $has_threshold = $this->db->tableColumnExists('examgrouping_groups', 'threshold_type');

        $select = $has_threshold
            ? "SELECT id, name, position, threshold_type, threshold_value"
            : "SELECT id, name, position";

        $result = $this->db->queryF(
            "$select FROM examgrouping_groups WHERE test_id = %s ORDER BY position",
            ['integer'],
            [$obj_id]
        );

        $groups = [];
        while ($row = $this->db->fetchAssoc($result)) {
            $groups[] = [
                'id'              => (int)   $row['id'],
                'name'            =>         $row['name'],
                'position'        => (int)   $row['position'],
                'threshold_type'  =>         $row['threshold_type']  ?? 'percent',
                'threshold_value' => (float)($row['threshold_value'] ?? 50.0),
            ];
        }

        return $groups;
    }

    private function getGroupQuestions(int $group_id): array
    {
        $result = $this->db->queryF(
            "SELECT question_id FROM examgrouping_items WHERE group_id = %s",
            ['integer'],
            [$group_id]
        );

        $qids = [];
        while ($row = $this->db->fetchAssoc($result)) {
            $qids[] = (int) $row['question_id'];
        }

        return $qids;
    }

    /**
     * Gibt abgeschlossene Testdurchläufe zurück (submitted = 1).
     * Liest nur tst_active, tst_test_result, usr_data.
     */
    /**
     * Gibt alle abgeschlossenen Passes zurück, alle Durchläufe je Teilnehmer.
     * Rückgabe: gruppiert nach active_id → array of passes (aufsteigend nach pass-Nr).
     * Jeder Eintrag enthält zusätzlich 'submitted_at' (Zeitstempel als string).
     */
    private function getSubmittedPasses(int $obj_id): array
    {
        $result = $this->db->queryF(
            "SELECT test_id FROM tst_tests WHERE obj_fi = %s",
            ['integer'],
            [$obj_id]
        );
        $row = $this->db->fetchAssoc($result);
        if (!$row) {
            return [];
        }
        $test_id = (int) $row['test_id'];

        // Alle Passes mit Ergebnissen laden (nicht nur letzten)
        $result = $this->db->queryF(
            "SELECT ta.active_id, ta.user_fi, ta.submittimestamp,
                    ud.login, ud.firstname, ud.lastname,
                    tr.pass
             FROM tst_active ta
             LEFT JOIN usr_data ud ON ta.user_fi = ud.usr_id
             INNER JOIN (
                 SELECT active_fi, pass
                 FROM tst_test_result
                 WHERE points IS NOT NULL
                 GROUP BY active_fi, pass
             ) tr ON tr.active_fi = ta.active_id
             WHERE ta.test_fi = %s AND ta.submitted = 1
             ORDER BY ud.lastname, ud.firstname, ta.active_id, tr.pass ASC",
            ['integer'],
            [$test_id]
        );

        $passes = [];
        while ($row = $this->db->fetchAssoc($result)) {
            $passes[] = [
                'active_id'    => (int) $row['active_id'],
                'user_fi'      => (int) $row['user_fi'],
                'login'        =>       $row['login']          ?? '',
                'firstname'    =>       $row['firstname']      ?? '',
                'lastname'     =>       $row['lastname']       ?? '',
                'pass'         => (int) $row['pass'],
                'submitted_at' =>       $row['submittimestamp'] ?? '',
            ];
        }

        return $passes;
    }

    /**
     * Gibt erreichte Punkte je Frage zurück für einen bestimmten Durchlauf.
     * tst_test_result.points = erreichte Punkte (ILIAS-Konvention).
     * Liest nur tst_test_result – keine Schreibzugriffe.
     */
    private function getQuestionResults(int $active_id, int $pass): array
    {
        $result = $this->db->queryF(
            "SELECT question_fi, points FROM tst_test_result WHERE active_fi = %s AND pass = %s",
            ['integer', 'integer'],
            [$active_id, $pass]
        );

        $results = [];
        while ($row = $this->db->fetchAssoc($result)) {
            $results[(int) $row['question_fi']] = (float) $row['points'];
        }

        return $results;
    }
}
