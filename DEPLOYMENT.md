# Änderungen prüfen und veröffentlichen

Änderungen können im Repository gespeichert werden. Der Workflow **App prüfen (ohne Upload)** prüft Änderungen an HTML, JavaScript, PHP, Tests und Workflows automatisch auf `main` und in Pull Requests. Er hat keine FTP-Zugangsdaten und veröffentlicht keine Dateien.

## Veröffentlichung nur nach Freigabe

Der Workflow **Badminton-App auf lima-city veröffentlichen** startet ausschließlich manuell. Die Voreinstellungen sind:

| Eingabe | Standard |
| --- | --- |
| Nur prüfen | an |
| HTML freigeben | aus |
| PHP freigeben | aus |

Für eine ausdrücklich beauftragte HTML-Veröffentlichung: Nur prüfen **aus**, HTML freigeben **an**, PHP freigeben **aus**. Der HTML-Schritt überträgt ausschließlich `index.html` nach `/default-website/Badminton/`, liest sie per FTPS zurück und vergleicht den Inhalt. Danach die öffentliche Seite https://badminton-app.de/Badminton/ prüfen. PHP erfordert immer eine separate ausdrückliche Freigabe; keine Sammel- oder Verzeichnissynchronisierung verwenden. Keine Live-Daten überschreiben.

Die feste Gruppe `badminton-deploy` verhindert parallele Uploads. Laufende Uploads nicht durch einen neuen Lauf abbrechen oder die Sperrgruppe ändern. Automatische Prüfungen haben eine eigene Gruppe und können keine Uploads blockieren.

## Grauer Kreis, keine Jobs, Abbruch schlägt fehl

1. Run-Status und Jobliste prüfen. `startup_failure` ohne Jobs bedeutet: Es gibt keine ausführbaren Joblogs; der FTP-Schritt wurde nicht gestartet.
2. Aktuellen Status auf https://www.githubstatus.com prüfen. Bei einer Actions-Störung keine Uploads wiederholt starten.
3. Nach Behebung zuerst **App prüfen (ohne Upload)** oder den Deploy-Workflow mit seinen sicheren Standardwerten ausführen.
4. Bleibt ein Lauf trotz Abbruch ohne Jobs hängen, die Run-URL und die Request-ID aus der Annotation an GitHub Support geben. Nicht die Parallelitätssperre umgehen. GitHubs Dokumentation beschreibt diesen Fehlerzustand ausdrücklich: https://docs.github.com/en/actions/how-tos/troubleshoot-workflows#canceling-workflows

Am 11.11.2026 scheiterten die Läufe `43095570587` und `43097497705` intern vor dem Jobstart. Zwischen dem letzten erfolgreichen Lauf und dem ersten Fehler war die Workflow-Datei unverändert. Bei der erneuten Prüfung am Abend meldete die offizielle Status-API `Actions: major_outage`. Das ist ein externer Fehler; Änderungen am HTML oder längere FTP-Zeitlimits beheben ihn nicht.
