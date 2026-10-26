# Hyundai Bluelink / Kia Connect – Symcon Anbindung

![Hyundai/Kia Bluelink — Kachel](https://raw.githubusercontent.com/da8ter/images/main/Bluelink%20Vollbild.png)

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
- Hyundai/Kia Account-E-Mail und Passwort
- PIN (4-stellig) für Remote-Aktionen

## Installation

1. Im IP-Symcon **Objektbaum** → **Kern Instanzen** → **Module Control** öffnen
2. URL hinzufügen: `https://github.com/da8ter/Bluelink`
3. **Bluelink Account** Instanz erstellen, **Marke** (Hyundai/Kia) wählen und Account-E-Mail/Passwort eintragen
4. **Bluelink Configurator** Instanz erstellen (verbindet sich automatisch mit dem Account)
5. Im Konfigurator die gewünschten Fahrzeuge als Instanzen anlegen

> **Beide Marken gleichzeitig nutzen:** Für Hyundai und Kia jeweils eine eigene Account-Instanz erstellen und in der Vehicle- und Konfigurator Instanz als Gateway auswählen.

## Authentifizierung

### OneApp/CCI-Anmeldung

Hyundai und Kia EU verwenden inzwischen den OneApp/CCI-Login. Im Account-Modul werden dazu die E-Mail-Adresse und das Passwort des Hyundai-/Kia-Kontos hinterlegt. Das Modul verschlüsselt das Passwort mit dem jeweils aktuell vom Hersteller gelieferten RSA-Schlüssel, bezieht den vollständigen CCI-Token-Satz und tauscht ihn gegen ein CCS-Zugriffstoken für die Fahrzeug-API.

1. Marke auswählen
2. Account-E-Mail und Passwort eintragen
3. Optional die vierstellige PIN für Remote-Aktionen eintragen
4. Änderungen übernehmen und **Login testen**

Access-, Refresh- und Begleittokens werden im Instanzpuffer gespeichert und automatisch erneuert. Das Passwort wird nicht in Debug-Ausgaben geschrieben.

Die frühere Refresh-Token-Property bleibt intern zur Kompatibilität mit bestehenden Installationen erhalten, wird bei neuen Konfigurationen aber nicht mehr angezeigt. Aktuelle Tokens werden vollständig vom Modul verwaltet.

## CCS2-Protokoll

Neuere Hyundai- und Kia-Fahrzeuge nutzen das **CCS2-Protokoll** (Connected Car Services v2). Das Modul erkennt dies automatisch über das Feld `ccuCCS2ProtocolSupport` in der Fahrzeugliste und verwendet dann den CCS2-Endpoint (`/ccs2/carstatus/latest`) mit erweitertem Response-Format.

**Vorteile des CCS2-Protokolls:**
- **Echtzeit-Ladeleistung** (`ChargingPowerKw`) über `RealTimePower`
- Erweiterte Batterie-Informationen (SOH, Temperatur, Kapazität)
- Detailliertere Klima- und Sitzheizungsdaten

Manche Fahrzeuge beantworten den CCS2-Endpoint, obwohl die Fahrzeugliste sie nicht als CCS2 kennzeichnet. Für sie gibt es in der Fahrzeug-Instanz die Option **„CCS2-Status verwenden“**. Wie viele zusätzliche Werte dann kommen, hängt vom Modell ab.

> **Hinweis:** Fahrzeuge ohne CCS2-Support verwenden weiterhin den Legacy-Endpoint. Die `ChargingPowerKw`-Variable zeigt dort 0.0 kW, da die Legacy-API diesen Wert nicht liefert.

## Weitere Fahrzeugwerte

Zusätzlich zu den Grundwerten liest das Modul, was das Auto meldet. Eine Variable entsteht erst, wenn das Auto den Wert zum ersten Mal liefert. Fahrzeuge ohne den passenden Sensor bekommen keine leeren Variablen.

- **Reifen:** Reifendruck je Rad in bar (nur CCS2-Fahrzeuge mit direkt messenden Reifensensoren), Reifendruck-Warnung gesamt und je Rad (alle Fahrzeuge). Fahrzeuge mit indirekter Reifendruckkontrolle liefern über die API nur die Warnungen, auch wenn die App Werte zeigt.
- **Warnungen:** Waschwasser, Bremsflüssigkeit, Schlüsselbatterie, Ölstand, 12-V-Batterie-Fehler, Leuchtmittel, Tank.
- **Klima und Komfort:** Außentemperatur, Sitzheizung und -kühlung je Sitz, Heckscheiben- und Spiegelheizung, Schiebedach, Verriegelung je Tür, fahrbereit, Fahrmodus.
- **Akku und Laden:** Akkugesundheit (SoH), Energie und Kapazität in kWh, geschätzte Ladedauer an Wallbox, Ladeziegel und Schnelllader, Reichweite beim Ladelimit AC/DC, Abfahrtszeiten 1 und 2, Nebenzeit-Laden, geplantes Laden (nur lesen).
- **Verbrauch:** Energieverbrauch und Rekuperation gesamt sowie Verbrauch der letzten 30 Tage (Elektro- und Plug-in-Fahrzeuge).

Welche Werte ein Auto liefert, hängt von Modell, Antrieb und Protokoll ab. In der Dashboard-Kachel lassen sich die Werte als Zeilen der Karte „Fahrzeug“ auswählen.

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
| `BL_UpdateDrivingStats($id)` | Fahrstatistik (Tageswerte) sofort abrufen |
| `BL_UpdateTrips($id)` | Fahrtenbuch der Dashboard-Kachel sofort abrufen |

## Lizenz

MIT
