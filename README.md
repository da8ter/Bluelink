# Hyundai Bluelink / Kia Connect – Symcon Anbindung

Hyundai Bluelink & Kia Connect Integration für Symcon 8.2 (9.0) (EU-Region).

Liest Fahrzeugdaten und steuert Remote-Aktionen über die Hyundai Bluelink / Kia Connect API. Unterstützt mehrere Fahrzeuge pro Account und beide Marken gleichzeitig.

## Module

| Modul | Beschreibung |
|-------|-------------|
| [Hyundai Bluelink Kia Connect Account](HyundaiBluelinkKiaConnectAccount/README.md) | Splitter – Verwaltet Anmeldedaten, Tokens und API-Kommunikation |
| [Hyundai Bluelink Kia Connect Configurator](HyundaiBluelinkKiaConnectConfigurator/README.md) | Konfigurator – Zeigt Fahrzeugliste und erstellt Fahrzeug-Instanzen |
| [Hyundai Bluelink Kia Connect Vehicle](HyundaiBluelinkKiaConnectVehicle/README.md) | Gerät – Status, Standort und Remote-Aktionen pro Fahrzeug |

## Voraussetzungen

- IP-Symcon >= 8.2 (9.0)
- Hyundai Bluelink oder Kia Connect Account (EU)
- Refresh Token (empfohlen)
- PIN (4-stellig) für Remote-Aktionen

## Installation

1. Im IP-Symcon **Objektbaum** → **Kern Instanzen** → **Module Control** öffnen
2. URL hinzufügen: `https://github.com/da8ter/Bluelink`
3. **Bluelink Account** Instanz erstellen, **Marke** (Hyundai/Kia) wählen und Refresh Token eintragen
4. **Bluelink Configurator** Instanz erstellen (verbindet sich automatisch mit dem Account)
5. Im Konfigurator die gewünschten Fahrzeuge als Instanzen anlegen

> **Beide Marken gleichzeitig nutzen:** Für Hyundai und Kia jeweils eine eigene Account-Instanz erstellen und in der Vehicle- und Konfigurator Instanz als Gateway auswählen.

## Authentifizierung

### Refresh Token (empfohlen)

Hyundai und Kia EU verwenden reCAPTCHA beim Login, was eine automatische Anmeldung erschwert. Der **Refresh Token** ist daher die zuverlässigste Methode:

1. Refresh Token extern erzeugen (z.B. über das [hyundai_kia_connect_api](https://github.com/Hyundai-Kia-Connect/hyundai_kia_connect_api/tree/master/Hyundai%20Token%20Solution) Python-Script)
2. Token im Account-Modul unter **Refresh Token** eintragen
3. Login testen

## CCS2-Protokoll

Neuere Hyundai- und Kia-Fahrzeuge nutzen das **CCS2-Protokoll** (Connected Car Services v2). Das Modul erkennt dies automatisch über das Feld `ccuCCS2ProtocolSupport` in der Fahrzeugliste und verwendet dann den CCS2-Endpoint (`/ccs2/carstatus/latest`) mit erweitertem Response-Format.

**Vorteile des CCS2-Protokolls:**
- **Echtzeit-Ladeleistung** (`ChargingPowerKw`) über `RealTimePower`
- Erweiterte Batterie-Informationen (SOH, Temperatur, Kapazität)
- Detailliertere Klima- und Sitzheizungsdaten

> **Hinweis:** Fahrzeuge ohne CCS2-Support verwenden weiterhin den Legacy-Endpoint. Die `ChargingPowerKw`-Variable zeigt dort 0.0 kW, da die Legacy-API diesen Wert nicht liefert.

## Bekannte Einschränkungen

- **EU Login-Flow**: Hyundai/Kia ändern den Login-Flow regelmäßig. Bei Problemen Refresh Token aktualisieren.
- **Stamps**: Die EU API erfordert signierte Stamps. Diese werden lokal per CFB-XOR-Algorithmus generiert.
- **12V-Batterie**: Häufige Fahrzeug-Refreshes (nicht Cloud-Status!) wecken das Auto und belasten die 12V-Batterie.
- **Rate Limiting**: Die API hat Rate Limits. Das Modul implementiert automatischen Backoff.
- **PIN**: Für alle Remote-Aktionen (Lock, Unlock, Klima, Laden) ist ein 4-stelliger PIN erforderlich.
- **ChargingPowerKw**: Nur bei Fahrzeugen mit CCS2-Protokoll verfügbar (Legacy-API liefert diesen Wert nicht).

## Architektur

```
HyundaiBluelinkKiaConnectAccount (Splitter, Brand=Hyundai)
    ├── HyundaiBluelinkKiaConnectConfigurator (Configurator)
    └── HyundaiBluelinkKiaConnectVehicle (Device) × n

HyundaiBluelinkKiaConnectAccount (Splitter, Brand=Kia)
    ├── HyundaiBluelinkKiaConnectConfigurator (Configurator)
    └── HyundaiBluelinkKiaConnectVehicle (Device) × n
```

Jede Account-Instanz verwaltet eine Marke. Für beide Marken gleichzeitig werden zwei Account-Instanzen erstellt.

Die Kommunikation läuft über den offiziellen IP-Symcon Datenfluss (`SendDataToParent` / `ForwardData`).

## Darstellungen (Vehicle)

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
