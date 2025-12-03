# 🧪 Strato-Kompatibilitäts-Testprotokoll

## Schnelltest-Checkliste

### ✅ Basis-Funktionalität

- [ ] **Plugin-Aktivierung**
  ```bash
  # Im WordPress-Admin: Plugins → Easy2Transfer Sync → Aktivieren
  # Erwartung: Keine Fehler, Admin-Menü erscheint
  ```

- [ ] **Admin-Zugriff**
  ```
  WordPress Admin → Easy2Transfer Sync
  Erwartung: Seite lädt ohne Fehler
  ```

- [ ] **Konfiguration speichern**
  - API-Token eingeben und speichern
  - Consent-Feld-ID prüfen (Standard: 282018660)
  - Batch-Größe prüfen (Standard: 200)
  - Erwartung: Alle Werte werden gespeichert

### ✅ Sync-Funktionalität

- [ ] **Kleiner Test-Sync (10 Mitglieder)**
  ```
  1. Batch-Größe temporär auf 10 setzen
  2. "Nur Mitglieder mit Consent abrufen" klicken
  3. Status beobachten
  Erwartung: Sync läuft durch, keine Timeout-Fehler
  ```

- [ ] **Vollständiger Sync**
  ```
  1. Batch-Größe auf 200 zurücksetzen
  2. Automatische Fortsetzung aktivieren
  3. Sync starten
  4. Alle Durchläufe beobachten
  Erwartung: 
    - Jeder Durchlauf < 15 Minuten
    - Automatische Fortsetzung funktioniert
    - Alle Teile werden zusammengeführt
  ```

- [ ] **Status-Tracking**
  ```
  Prüfe: wp-content/uploads/easy2transfer-sync/status.json
  Erwartung: Aktueller Status wird korrekt gespeichert
  ```

- [ ] **Log-Dateien**
  ```
  Prüfe: wp-content/uploads/easy2transfer-sync/sync.log
  Erwartung: Log-Einträge werden geschrieben
  ```

### ✅ Frontend-Funktionalität

- [ ] **Mitglieder-Shortcode**
  ```
  1. Seite mit [e2t_members] erstellen/bearbeiten
  2. Seite aufrufen
  Erwartung: Mitgliederkarten werden angezeigt
  ```

- [ ] **Filter & Suche**
  ```
  1. Auf Mitgliederseite gehen
  2. Filter testen
  3. Suche testen
  Erwartung: Filter und Suche funktionieren
  ```

- [ ] **Kalender-Shortcode**
  ```
  1. Seite mit [e2t_kalender id="transfer"] erstellen
  2. Seite aufrufen
  Erwartung: Kalender-Events werden angezeigt
  ```

### ✅ Felderverwaltung

- [ ] **Felder laden**
  ```
  Admin → Easy2Transfer Sync → Felder-Tab
  Erwartung: Alle Felder werden geladen
  ```

- [ ] **Felder konfigurieren**
  ```
  1. Feld als Favorit markieren
  2. Label ändern
  3. In Bereich verschieben (above/below)
  4. Speichern
  Erwartung: Alle Änderungen werden gespeichert
  ```

- [ ] **Quick-Actions**
  ```
  1. "Nur konfigurierte" klicken
  2. "Nur unkonfigurierte" klicken
  3. "Alle anzeigen" klicken
  Erwartung: Filter funktionieren korrekt
  ```

---

## 🔍 Erweiterte Tests

### Performance-Test

```bash
# Während des Syncs auf Strato-Server:
# 1. Memory-Verbrauch prüfen
# 2. CPU-Auslastung prüfen
# 3. Execution-Time prüfen

# Erwartung:
# - Memory < 128 MB pro Durchlauf
# - Execution-Time < 15 Minuten pro Durchlauf
```

### Error-Log prüfen

```bash
# WordPress Debug-Log
tail -f wp-content/debug.log

# Plugin-Log
tail -f wp-content/uploads/easy2transfer-sync/sync.log

# Erwartung: Keine kritischen Fehler
```

### Datenintegrität

```bash
# Prüfe JSON-Dateien
cat wp-content/uploads/easy2transfer-sync/members_consent.json | jq '._meta'

# Erwartung:
# - Korrekte Metadaten
# - Alle Teile zusammengeführt
# - Keine fehlenden Daten
```

---

## 🚨 Bekannte Probleme & Lösungen

### Problem: Timeout nach 15 Minuten
**Symptom:** Sync bricht ab, Status zeigt "error"
**Lösung:** Batch-Größe reduzieren (z.B. auf 150)

### Problem: Memory-Limit erreicht
**Symptom:** Fatal error: Allowed memory size exhausted
**Lösung:** Batch-Größe weiter reduzieren

### Problem: WP-Cron läuft nicht
**Symptom:** Sync startet nicht automatisch
**Lösung:** Manuell `wp-cron.php` aufrufen oder echten Cron einrichten

### Problem: Dateien können nicht geschrieben werden
**Symptom:** Fehler beim Speichern von JSON-Dateien
**Lösung:** Ordner-Berechtigungen prüfen (755 für Ordner, 644 für Dateien)

---

## 📊 Test-Ergebnis

**Datum:** _______________
**Tester:** _______________
**Strato-PHP-Version:** _______________
**WordPress-Version:** _______________

### Ergebnis: ☐ Bestanden  ☐ Fehler

### Fehler-Details:
```
[Falls Fehler aufgetreten sind, hier dokumentieren]
```

### Empfehlung:
```
☐ Bereit für Produktion
☐ Weitere Tests nötig
☐ Anpassungen erforderlich
```

---

## ✅ Sign-off

Nach erfolgreichem Test:
- [ ] Alle Basis-Tests bestanden
- [ ] Alle Sync-Tests bestanden
- [ ] Frontend funktioniert
- [ ] Keine kritischen Fehler
- [ ] Performance akzeptabel
- [ ] Backup erstellt
- [ ] **Bereit für Refactoring**

**Unterschrift:** _______________
**Datum:** _______________


