# BKiAI Chat Free

WordPress-Plugin für einen KI-Chat auf der Website.

## Version

Aktueller GitHub-Stand in diesem Paket: **3.1.0**

## Inhalt von 3.1.0

- Design-Einstellung für die Standardhöhe des Eingabefelds
- Drei animierte Punkte vor der Chatantwort
- Natürlichere Schreibgeschwindigkeit bei gestreamten Antworten

## Wichtige Hinweise für GitHub

- Für Endnutzer sollte **nicht** der grüne **Code → Download ZIP**-Button verwendet werden.
- Für die Installation in WordPress sollte immer das **Release-Asset** verwendet werden, also die Datei:
  - `bkiai-chat-free-v3.1.0.zip`
- GitHub dient hier als:
  - Versionsverwaltung
  - Quellcode-Repository
  - Release-Historie
  - zusätzlicher Download-Kanal

## Empfohlene Repository-Struktur

- `bkiai-chat-free.php`
- `readme.txt`
- `README.md`
- `CHANGELOG.md`
- `RELEASE-NOTES-3.1.0.md`
- `assets/`
- `includes/`
- `languages/`
- `uninstall.php`

## WordPress-Shortcode

```text
[bkiai_chat bot="1"]
```

## Release-Prozess

1. Dateien committen
2. Tag `v3.1.0` anlegen
3. GitHub Release erstellen
4. `bkiai-chat-free-v3.1.0.zip` als Release-Asset hochladen
5. Release Notes einfügen

## Nächster technischer Schritt

Als separater Arbeitsblock sollte der Update-Prozess eingerichtet werden, damit WordPress-Installationen später neue Versionen sauber erkennen und aktualisieren können.
