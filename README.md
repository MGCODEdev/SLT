# Source Link Tool

**Source Link Tool** ist ein Open-Source-Projekt, das ein CRUD-System (Create, Read, Update, Delete) für Links implementiert. Die Daten werden in einer JSON-Datei gespeichert. Das Projekt basiert auf dem [NiceAdmin-Template](https://bootstrapmade.com/nice-admin-bootstrap-admin-html-template/) und nutzt Bootstrap für das Layout. Zusätzlich gibt es erweiterte Funktionen wie Import/Export von Links und Einstellungen, konfigurierbare globale Einstellungen (Farben, Überschrift etc.), Bild/Icon-Upload (inklusive automatischem Resizing) und eine Info-Seite mit Release Notes.

## Features

- **CRUD-Funktionalität**: Erstelle, lese, bearbeite und lösche Links.
- **Datenhaltung**: Speicherung der Links in einer JSON-Datei (`links.json`).
- **Einstellungen**: Globale Einstellungen (Überschrift, Primär-, Sekundär-, Hintergrund- und Textfarbe) können über die Settings-Seite konfiguriert und per Import/Export verwaltet werden.
- **Bild/Icon-Upload**: Icons können zu Links hinzugefügt werden. Die Icons werden automatisch auf 64x64 Pixel verkleinert und im Ordner `linkbilder` gespeichert.
- **Export/Import von Linkbildern**: Exportiere und importiere den gesamten Ordner `linkbilder` als ZIP-Datei (benötigt die PHP-Erweiterung *ZipArchive*).
- **Info-Seite**: Anzeige der Release Notes und Informationen zum Projekt.
- **CSRF-Schutz und Flash-Messages** für sichere Formularübertragungen.

## Anforderungen

- **Webserver**: Apache, Nginx oder ein anderer moderner Webserver.
- **PHP**: Version 7.0 oder höher (Funktion `random_bytes()` muss verfügbar sein).
- **PHP-Erweiterungen**:
  - **GD**: Zur Bildverarbeitung (für den Icon-Upload und Resizing).
  - **ZipArchive**: Für den Export/Import der Linkbilder (optional, aber benötigt für diese Funktion).
- **Dateiberechtigungen**:
  - Das Hauptverzeichnis (z. B. `/volume2/web/Source-Link-Tool/`) muss Schreib-/Leserechte für den Webserver besitzen.
  - Die Datei `links.json` und `settings.json` müssen beschreibbar sein (z. B. Rechte `666` oder passend per Besitzer/Zugriffssteuerung).
  - Der Unterordner `linkbilder` muss existieren und beschreibbar sein (z. B. Rechte `755` oder `777`, abhängig von der Serverkonfiguration).

## Installation

1. **Repository klonen:**

   ```bash
   git clone https://github.com/MGCODEdev/SLT.git
   cd source-link-tool

