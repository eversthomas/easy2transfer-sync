# 🔒 Sicherungs- und Testanleitung für Easy2Transfer Sync

## 📦 Aktueller Stand sichern

### Version: 2.9 (Pre-Refactoring)
**Datum:** $(date +%Y-%m-%d)

### 1. Komplettes Plugin-Verzeichnis sichern

```bash
# Im WordPress-Plugin-Verzeichnis
cd wp-content/plugins/
tar -czf easy2transfer-sync-v2.9-backup-$(date +%Y%m%d).tar.gz easy2transfer-sync/
```

### 2. Wichtige Dateien für manuelle Sicherung

**Kern-Dateien:**
- `easy2transfer-sync.php` (Hauptdatei)
- `sync/api-core.php`
- `sync/api-core-consent.php`
- `sync/cron.php`
- `admin/fields-handler.php`
- `frontend/renderer.php`

**Konfigurations-Dateien (in wp-content/uploads/easy2transfer-sync/):**
- `fields-config.json` (Feldkonfiguration)
- `members_consent.json` (Mitgliederdaten)
- `status.json` (Sync-Status)

**WordPress-Optionen (in Datenbank):**
- `e2t_api_token`
- `e2t_consent_field_id`
- `e2t_batch_size`
- `e2t_auto_continue`
- `e2t_calendars`

### 3. Git-Versionierung (empfohlen)

```bash
cd easy2transfer-sync/
git init
git add .
git commit -m "v2.9 - Pre-Refactoring Stand (Strato-kompatibel)"
git tag v2.9-stable
```

---

## ✅ Strato-Kompatibilitäts-Checkliste

### PHP-Version
- ✅ **Erforderlich:** PHP 7.4+ (Strato unterstützt PHP 7.4 - 8.2)
- ✅ **Aktuell verwendet:** PHP 7.4+ Features (Typed Properties, Nullable Types)
- ⚠️ **Prüfen:** `php -v` auf Strato-Server

### WordPress-Kompatibilität
- ✅ **Erforderlich:** WordPress 5.0+
- ✅ **Getestet mit:** WordPress 6.x
- ⚠️ **Prüfen:** WordPress-Version auf Strato

### Hosting-Limitierungen (Strato)

#### 1. Ausführungszeit
- ⚠️ **Limit:** 15 Minuten pro Cronjob
- ✅ **Aktuell:** Batch-System mit 200 Mitgliedern pro Durchlauf
- ✅ **Implementiert:** Automatische Fortsetzung
- ⚠️ **Test:** Vollständigen Sync durchführen

#### 2. Memory-Limit
- ⚠️ **Typisch:** 128-256 MB
- ✅ **Aktuell:** Keine großen Arrays im Memory
- ⚠️ **Prüfen:** `ini_get('memory_limit')` auf Strato

#### 3. File-Upload-Limit
- ⚠️ **Typisch:** 10-50 MB
- ✅ **Aktuell:** JSON-Dateien, keine großen Uploads
- ⚠️ **Prüfen:** Größe von `members_consent.json`

#### 4. WP-Cron
- ⚠️ **Limit:** 15 Minuten
- ✅ **Aktuell:** Verwendet WP-Cron mit Batch-System
- ⚠️ **Test:** WP-Cron manuell auslösen

#### 5. set_time_limit()
- ⚠️ **Problem:** Strato kann `set_time_limit(0)` ignorieren
- ✅ **Aktuell:** Verwendet `set_time_limit(0)` in Sync-Funktionen
- ⚠️ **Test:** Ob Timeout trotzdem greift

---

## 🧪 Test-Checkliste für Strato

### Vor dem Deployment

#### 1. Lokale Tests
- [ ] Vollständiger Consent-Sync durchläuft
- [ ] Alle 3 Durchläufe funktionieren
- [ ] Automatische Fortsetzung funktioniert
- [ ] Felderverwaltung speichert korrekt
- [ ] Frontend zeigt Mitglieder an
- [ ] Kalender funktioniert

#### 2. Code-Review
- [ ] Keine PHP-Fehler in Error-Log
- [ ] Keine JavaScript-Fehler in Browser-Konsole
- [ ] Alle `require_once` Pfade korrekt
- [ ] Keine hardcoded Pfade

### Auf Strato-Server

#### 1. Basis-Tests
- [ ] Plugin aktiviert sich ohne Fehler
- [ ] Admin-Seite lädt korrekt
- [ ] API-Token kann gespeichert werden
- [ ] Consent-Feld-ID kann gespeichert werden

#### 2. Sync-Tests
- [ ] Test-Sync mit 10 Mitgliedern
- [ ] Vollständiger Sync (alle Durchläufe)
- [ ] Automatische Fortsetzung
- [ ] Status-Updates funktionieren
- [ ] Log-Dateien werden geschrieben

#### 3. Frontend-Tests
- [ ] Shortcode `[e2t_members]` funktioniert
- [ ] Mitgliederkarten werden angezeigt
- [ ] Filter funktionieren
- [ ] Kalender-Shortcode funktioniert

#### 4. Performance-Tests
- [ ] Sync bleibt unter 15 Minuten pro Durchlauf
- [ ] Memory-Verbrauch bleibt unter Limit
- [ ] Keine Timeout-Fehler
- [ ] JSON-Dateien werden korrekt geschrieben

---

## 🔍 Debugging auf Strato

### Log-Dateien prüfen

```bash
# WordPress Debug-Log
tail -f wp-content/debug.log

# Plugin-spezifische Logs
tail -f wp-content/uploads/easy2transfer-sync/sync.log
tail -f wp-content/uploads/easy2transfer-sync/debug.log
```

### Status-Datei prüfen

```bash
cat wp-content/uploads/easy2transfer-sync/status.json
```

### PHP-Info prüfen

Erstelle temporär `phpinfo.php`:
```php
<?php phpinfo(); ?>
```

Prüfe:
- `max_execution_time`
- `memory_limit`
- `upload_max_filesize`
- PHP-Version

---

## 📋 Deployment-Checkliste

### Vor dem Upload
- [ ] Komplettes Backup erstellt
- [ ] Alle Tests lokal bestanden
- [ ] Version-Nummer aktualisiert (falls nötig)
- [ ] Debug-Modus deaktiviert (`E2T_DEBUG_MODE = false`)

### Nach dem Upload
- [ ] Plugin aktivieren
- [ ] Konfiguration prüfen (Token, Consent-ID)
- [ ] Test-Sync durchführen
- [ ] Frontend testen
- [ ] Logs prüfen

### Rollback-Plan
1. Plugin deaktivieren
2. Backup-Verzeichnis wiederherstellen
3. Plugin erneut aktivieren
4. Konfiguration prüfen

---

## 🚨 Bekannte Strato-spezifische Probleme

### Problem 1: set_time_limit(0) wird ignoriert
**Lösung:** Batch-System bereits implementiert

### Problem 2: WP-Cron Timeout nach 15 Minuten
**Lösung:** Automatische Fortsetzung implementiert

### Problem 3: Memory-Limit bei großen Datenmengen
**Lösung:** Streaming-Verarbeitung, keine großen Arrays

### Problem 4: File-Permissions
**Lösung:** `wp_mkdir_p()` verwendet WordPress-Funktionen

---

## 📝 Notizen

### Getestet am: [DATUM]
### Getestet von: [NAME]
### Strato-PHP-Version: [VERSION]
### WordPress-Version: [VERSION]
### Ergebnis: [OK / FEHLER]

### Bekannte Probleme:
- [Liste der Probleme]

### Workarounds:
- [Liste der Workarounds]

---

## 🔄 Nach erfolgreichem Test

Wenn alle Tests bestanden:
1. ✅ Status dokumentieren
2. ✅ Backup als "v2.9-stable-strato" taggen
3. ✅ Refactoring kann beginnen
4. ✅ Neue Version als "v3.0-dev" starten


