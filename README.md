# BKiAI Chat Free

**Version:** 3.0.0  
**Typ:** WordPress-Plugin (Free Edition)  
**Website:** https://businesskiai.de/  
**Plugin-Seite:** https://businesskiai.de/bki-ai-chat/

BKiAI Chat Free fügt deiner WordPress-Website einen KI-Chat hinzu und bildet die öffentliche Free-Edition der Produktlinie **BKiAI Chat**.

Die Free-Version ist bewusst als Einstiegsversion aufgebaut. Sie enthält einen klaren Funktionsumfang und zeigt transparent, welche Funktionen in **Pro** und **Expert** verfügbar sind.

## Enthalten in der Free-Version

- KI-Chat für WordPress
- Voice Recording in der Free-Version
- Anpassung der Chat-Rahmenfarbe
- Anpassung der Rahmenstärke
- 1 aktiver Bot in der Free-Version
- 1 Wissensdatei für Bot 1
- Compare-/Upgrade-Bereich im Plugin
- Übersicht Free vs. Pro vs. Expert
- GPT-Modellübersicht

## Produktlinie

- **BKiAI Chat Free**
- **BKiAI Chat Pro**
- **BKiAI Chat Expert**

## Installation

### Empfohlener Weg für Nutzer

Bitte **nicht** die automatische GitHub-Quellcode-ZIP über den grünen „Code → Download ZIP“-Button in WordPress installieren.

Nutze stattdessen immer die **installierbare Plugin-ZIP aus den GitHub Releases**.

### Installation in WordPress

1. Lade die installierbare Plugin-ZIP aus den **Releases** herunter.
2. Gehe in WordPress zu **Plugins → Installieren → Plugin hochladen**.
3. Lade die ZIP hoch und aktiviere das Plugin.
4. Öffne **Einstellungen → BKiAI Chat**.
5. Konfiguriere die allgemeinen Einstellungen und **Bot 1**.
6. Binde den Chat per Shortcode ein:

```text
[bkiai_chat bot="1"]
```

## Repository-Struktur

```text
assets/
includes/
languages/
bkiai-chat-free.php
readme.txt
uninstall.php
README.md
CHANGELOG.md
LICENSE
.gitignore
```

## Hinweise zu GitHub und Releases

Dieses Repository enthält den **Quellcode der Free-Version**.

Für echte Plugin-Installationen in WordPress solltest du die **Release-ZIP** verwenden. Die automatisch von GitHub erzeugte Source-Code-ZIP ist nicht die empfohlene Installationsdatei für Endnutzer.

## Upgrade auf Pro oder Expert

Die kostenpflichtigen Editionen **Pro** und **Expert** werden nicht über dieses öffentliche Repository ausgeliefert.

Weitere Informationen:

- https://businesskiai.de/
- https://businesskiai.de/bki-ai-chat/

## Externe Dienste und Datenschutz

BKiAI Chat Free sendet Chat-Anfragen an **OpenAI**, um Antworten für den Chatbot zu erzeugen.

Je nach Konfiguration können dabei übertragen werden:

- Nutzeranfragen
- Systemprompts
- optionale Inhalte aus hochgeladenen Wissensdateien

Website-Betreiber sind selbst dafür verantwortlich, Datenschutzinformationen, Rechtsgrundlagen und mögliche Vereinbarungen mit externen Diensten zu prüfen.

## Support

Bei Fragen:

- info@businesskiai.de

## Lizenz

Dieses Projekt steht unter **GPL v2 oder später**. Details siehe Datei `LICENSE`.
