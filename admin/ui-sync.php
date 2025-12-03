<?php
if (!defined('ABSPATH')) exit;

/**
 * Sync-Tab: Verwaltung der Easy2Transfer-Synchronisierung
 * (ausgelagert aus easy2transfer-sync.php)
 */
?>

<form method="post" class="e2t-sync-form">
    <table class="form-table">
        <tr>
            <th><label for="e2t_token">API-Token</label></th>
            <td>
                <input type="text" id="e2t_token" name="e2t_token"
                    value="<?php echo esc_attr(get_option('e2t_api_token', '')); ?>"
                    style="width:400px;">
            </td>
        </tr>
        <tr>
            <th><label for="e2t_consent_field_id">Consent-Feld-ID</label></th>
            <td>
                <input type="number" id="e2t_consent_field_id" name="e2t_consent_field_id"
                    value="<?php echo esc_attr(get_option('e2t_consent_field_id', '282018660')); ?>"
                    style="width:200px;">
                <p class="description">ID des Custom Fields, das die Einwilligung zur Veröffentlichung bestimmt. Standard: 282018660</p>
            </td>
        </tr>
        <tr>
            <th><label for="e2t_batch_size">Batch-Größe pro Durchlauf</label></th>
            <td>
                <input type="number" id="e2t_batch_size" name="e2t_batch_size" min="50" max="500"
                    value="<?php echo esc_attr(get_option('e2t_batch_size', '200')); ?>"
                    style="width:200px;">
                <p class="description">Anzahl der Mitglieder, die pro Durchlauf verarbeitet werden. Empfohlen: 200 (für Strato-Hosting).</p>
            </td>
        </tr>
        <tr>
            <th><label for="e2t_auto_continue">Automatische Fortsetzung</label></th>
            <td>
                <label>
                    <input type="checkbox" id="e2t_auto_continue" name="e2t_auto_continue" value="1"
                        <?php checked(get_option('e2t_auto_continue', false)); ?>>
                    Automatisch mit dem nächsten Durchlauf fortfahren
                </label>
                <p class="description">Wenn aktiviert, wird nach Abschluss eines Durchlaufs automatisch der nächste gestartet.</p>
            </td>
        </tr>
    </table>
    <p>
        <button type="submit" class="button">Token speichern</button>
        &nbsp;
        <!-- direkt hier drunter ent-kommentieren, falls alle Mitglieder abgerufen werden können sollen -->
        <!-- <button disabled type="button" id="e2tStart" class="button button-primary">🔄 Alle Mitglieder abrufen</button>
        &nbsp; -->
        <button type="button" id="e2tStartConsent" class="button button-secondary">✅ Nur Mitglieder mit Consent abrufen</button>
        <button type="button" id="e2tMergeParts" class="button">🔗 Alle Teile zusammenführen</button>
    </p>
    <p class="description">
        <strong>Hinweis:</strong> Wenn du die Durchläufe manuell gemacht hast, klicke auf "Alle Teile zusammenführen", um die finale members_consent.json zu erstellen.
    </p>
</form>

<div id="e2tProgress" class="e2t-progress" style="margin-top:20px;padding:10px;border:1px solid #ccc;display:none;">
    <div style="margin-bottom:8px;">Gesamtfortschritt:</div>
    <div id="e2tBar" style="height:20px;background:#eee;border-radius:5px;margin-bottom:10px;">
        <div id="e2tBarInner" style="height:20px;width:0;background:#0073aa;border-radius:5px;"></div>
    </div>

    <div style="margin-bottom:8px;">Aktueller Schritt:</div>
    <div id="e2tBar2" style="height:10px;background:#f0f0f0;border-radius:5px;">
        <div id="e2tBarInner2" style="height:10px;width:0;background:#00a32a;border-radius:5px;"></div>
    </div>

    <p id="e2tStatus" style="margin-top:8px;font-weight:bold;">Bereit.</p>
</div>

<script>
    jQuery(function($) {
        const btnAll = $('#e2tStart');
        const btnConsent = $('#e2tStartConsent');
        const bar1 = $('#e2tBarInner');
        const bar2 = $('#e2tBarInner2');
        const status = $('#e2tStatus');
        const box = $('#e2tProgress');

        function poll() {
          $.get(ajaxurl, { action: 'e2t_status' }, function (resp) {
            if (!resp.success) return;
            const s = resp.data;
            bar1.css('width', (s.progress || 0) + '%');
            status.text(s.message || '');
            if (s.state === 'running') setTimeout(poll, 3000);
          });
        }

		// =====================================================
        // 🔁  Automatische Statusaktualisierung alle 5 Sekunden
        // =====================================================
        setInterval(function() {
          $.get(ajaxurl, { action: 'e2t_status' }, function (resp) {
            if (!resp.success) return;
            const s = resp.data;
            if (!s) return;

            // Fortschrittsbalken
            $('#e2tBarInner').css('width', (s.progress || 0) + '%');
            $('#e2tStatus').text(s.message || '');

            // Wenn Sync fertig, Buttons wieder aktivieren
            if (s.state === 'done') {
              $('#e2tStartConsent').prop('disabled', false).text('✅ Nur Mitglieder mit Consent abrufen');
            }
          });
        }, 5000);


        function startSync(action, btn) {
            btn.prop('disabled', true).text('Starte...');
            box.show();
            status.text('Plane WP-Cron Event ...');

            $.post(ajaxurl, {
                action: action
            }, function(resp) {
                if (resp.success) {
                    status.text(resp.data.msg || 'Sync gestartet ...');
                    
                    // Zeige Info über nächsten Durchlauf
                    if (resp.data.next_part && resp.data.total_parts) {
                        status.text(resp.data.msg + ' (Durchlauf ' + resp.data.next_part + ' von ' + resp.data.total_parts + ')');
                    }
                    
                    // Automatische Fortsetzung direkt auslösen (nicht über Status-Polling)
                    if (resp.data.auto_continue && resp.data.next_part) {
                        console.log('E2T: Automatische Fortsetzung aktiviert', resp.data);
                        status.text('Starte automatisch Durchlauf ' + resp.data.next_part + ' in 2 Sekunden...');
                        
                        // Rekursive Funktion für automatische Fortsetzung
                        function continueAutoSync() {
                            setTimeout(function() {
                                console.log('E2T: Starte automatisch nächsten Durchlauf');
                                $.post(ajaxurl, {
                                    action: 'e2t_start_consent'
                                }, function(nextResp) {
                                    console.log('E2T: Antwort auf automatischen Start', nextResp);
                                    if (nextResp.success) {
                                        status.text(nextResp.data.msg || 'Nächster Durchlauf gestartet...');
                                        setTimeout(poll, 2000);
                                        
                                        // Prüfe ob weitere Durchläufe nötig sind
                                        if (nextResp.data.auto_continue && nextResp.data.next_part) {
                                            // Erneut automatisch fortsetzen
                                            continueAutoSync();
                                        } else if (nextResp.data.completed) {
                                            status.text('✅ Alle Durchläufe abgeschlossen!');
                                            btn.prop('disabled', false).text(btn.data('label'));
                                        } else {
                                            // Keine automatische Fortsetzung mehr, aber auch nicht fertig
                                            btn.prop('disabled', false).text(btn.data('label'));
                                        }
                                    } else {
                                        status.text('Fehler beim automatischen Start: ' + (nextResp.data.error || 'Unbekannt'));
                                        btn.prop('disabled', false).text(btn.data('label'));
                                    }
                                });
                            }, 2000);
                        }
                        
                        continueAutoSync();
                    } else {
                        console.log('E2T: Keine automatische Fortsetzung', resp.data);
                        if (resp.data.completed) {
                            btn.prop('disabled', false).text(btn.data('label'));
                        }
                        setTimeout(poll, 5000);
                    }
                } else {
                    status.text('Fehler: ' + (resp.data.error || 'Unbekannt'));
                    btn.prop('disabled', false).text(btn.data('label'));
                }
            });
        }

        btnAll.data('label', '🔄 Alle Mitglieder abrufen');
        btnConsent.data('label', '✅ Nur Mitglieder mit Consent abrufen');

        btnAll.on('click', function() {
            startSync('e2t_start', btnAll);
        });

        btnConsent.on('click', function() {
            startSync('e2t_start_consent', btnConsent);
        });

        // Zusammenführung-Button
        $('#e2tMergeParts').on('click', function() {
            const btn = $(this);
            btn.prop('disabled', true).text('Führe zusammen...');
            
            $.post(ajaxurl, {
                action: 'e2t_merge_parts'
            }, function(resp) {
                if (resp.success) {
                    status.text(resp.data.msg || 'Zusammenführung erfolgreich');
                    box.show();
                } else {
                    status.text('Fehler: ' + (resp.data.error || 'Unbekannt'));
                    box.show();
                }
                btn.prop('disabled', false).text('🔗 Alle Teile zusammenführen');
            });
        });

        // Autostart bei laufendem Sync
        $.get(ajaxurl, {
            action: 'e2t_status'
        }, function(resp) {
            if (resp.success && resp.data.state === 'running') {
                box.show();
                btnAll.prop('disabled', true).text('Läuft ...');
                btnConsent.prop('disabled', true).text('Läuft ...');
                poll();
            }
        });
    });
</script>