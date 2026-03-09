# Bluelink – IP-Symcon Anbindung

Hyundai Bluelink Integration für IP-Symcon 8.2 (EU-Region).

Liest Fahrzeugdaten und steuert Remote-Aktionen über die Hyundai Bluelink API. Unterstützt mehrere Fahrzeuge pro Account.

## Module

| Modul | Beschreibung |
|-------|-------------|
| [Bluelink Account](BluelinkAccount/README.md) | Splitter – Verwaltet Anmeldedaten, Tokens und API-Kommunikation |
| [Bluelink Configurator](BluelinkConfigurator/README.md) | Konfigurator – Zeigt Fahrzeugliste und erstellt Fahrzeug-Instanzen |
| [Bluelink Vehicle](BluelinkVehicle/README.md) | Gerät – Status, Standort und Remote-Aktionen pro Fahrzeug |

## Voraussetzungen

- IP-Symcon >= 8.2
- Hyundai Bluelink Account (EU)
- Refresh Token (empfohlen) oder Benutzername/Passwort
- PIN (4-stellig) für Remote-Aktionen

## Installation

1. Im IP-Symcon **Objektbaum** → **Kern Instanzen** → **Module Control** öffnen
2. URL hinzufügen: `https://github.com/da8ter/Bluelink`
3. **Bluelink Account** Instanz erstellen und Anmeldedaten eingeben
4. **Bluelink Configurator** Instanz erstellen (verbindet sich automatisch mit dem Account)
5. Im Konfigurator die gewünschten Fahrzeuge als Instanzen anlegen

## Authentifizierung

### Refresh Token (empfohlen)

Hyundai EU verwendet reCAPTCHA beim Login, was eine automatische Anmeldung erschwert. Der **Refresh Token** ist daher die zuverlässigste Methode:

1. Refresh Token extern erzeugen (z.B. über das [hyundai_kia_connect_api](https://github.com/Hyundai-Kia-Connect/hyundai_kia_connect_api) Python-Script)
2. Token im Account-Modul unter **Refresh Token** eintragen
3. Login testen

### Benutzername/Passwort (Fallback)

Funktioniert nur, wenn Hyundai kein reCAPTCHA erzwingt. Kann jederzeit brechen.

## Bekannte Einschränkungen

- **EU Login-Flow**: Hyundai ändert den Login-Flow regelmäßig. Bei Problemen Refresh Token aktualisieren.
- **Stamps**: Die EU API erfordert signierte Stamps. Diese werden lokal per CFB-XOR-Algorithmus generiert (kein externer Dienst nötig).
- **12V-Batterie**: Häufige Fahrzeug-Refreshes (nicht Cloud-Status!) wecken das Auto und belasten die 12V-Batterie. Mindestintervall: 30 Minuten.
- **Rate Limiting**: Die API hat Rate Limits. Das Modul implementiert automatischen Backoff.
- **PIN**: Für alle Remote-Aktionen (Lock, Unlock, Klima, Laden) ist ein 4-stelliger PIN erforderlich.

## Architektur

```
BluelinkAccount (Splitter)
    ├── BluelinkConfigurator (Configurator)
    └── BluelinkVehicle (Device) × n
```

Die Kommunikation läuft über den offiziellen IP-Symcon Datenfluss (`SendDataToParent` / `ForwardData`).

## Darstellungen (BluelinkVehicle)

- `DoorsLocked` wird als **schaltbare Aufzählung** mit den Texten **Locked/Unlocked** dargestellt, damit Symcon nicht **On/Off** verwendet.
- Tür-/Fensterstatus werden als **Wertanzeige** auf `bool` modelliert und mit den Texten **Open/Closed** dargestellt.
- `TrunkOpen`, `HoodOpen` und `PluggedIn` werden ebenfalls als **Wertanzeige** mit fachlichen Texten statt **On/Off** dargestellt.
- Der Ladezustand bleibt wegen der drei Zustände `0/1/2` ein `int` und wird als **Wertanzeige** über **Intervalle** beschriftet.
- Schalter mit fachlichen Zuständen nutzen semantische Texte, z.B. **Locked/Unlocked** statt **On/Off**.
- `ChargeLimitAC` und `ChargeLimitDC` werden als **Schieberegler** (50–100%, Step 10) dargestellt und sind **schaltbar**.
- Prozent-/Zeitwerte nutzen **Wertanzeige** mit Suffix (`%`, `min`) und passenden Icons.
- Die Darstellungen werden direkt über `RegisterVariable*()` mit nativen Symcon-Presentations gesetzt.
- Bei **Wertanzeige** werden Texte für `bool` über `OPTIONS` und für numerische Zustände über `INTERVALS` gesetzt.

## PHP-Befehle

### Account

| Befehl | Beschreibung |
|--------|-------------|
| `BL_TestLogin($id)` | Testet die Anmeldung |
| `BL_LoadVehicles($id)` | Lädt die Fahrzeugliste |

### Fahrzeug

| Befehl | Beschreibung |
|--------|-------------|
| `BL_UpdateStatus($id)` | Cloud-Status aktualisieren |
| `BL_RefreshVehicleStatus($id)` | Fahrzeug wecken und Status holen |
| `BL_RefreshLocation($id)` | Standort aktualisieren |
| `BL_Lock($id)` | Fahrzeug verriegeln |
| `BL_Unlock($id)` | Fahrzeug entriegeln |
| `BL_ClimateStart($id, $temperature)` | Klimaanlage starten |
| `BL_ClimateStop($id)` | Klimaanlage stoppen |
| `BL_ChargeStart($id)` | Laden starten |
| `BL_ChargeStop($id)` | Laden stoppen |
| `BL_SetChargeTargets($id, $limitAC, $limitDC)` | Ladelimits setzen (50–100%) |

## Lizenz

MIT
