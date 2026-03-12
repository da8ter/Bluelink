# Bluelink Vehicle

## Funktionsumfang

Device-Modul für ein einzelnes Hyundai- oder Kia-Fahrzeug. Pro Fahrzeug wird eine Instanz erstellt (über den Konfigurator).

Funktionen:
- Automatischer Statusabruf (Cloud) alle 300 Sekunden (konfigurierbar)
- Optionaler Fahrzeug-Refresh (weckt das Auto)
- Remote-Aktionen: Verriegeln, Entriegeln, Klimaanlage, Laden
- Standortabfrage
- EV-Daten: Ladezustand, Reichweite, Ladestatus

## Voraussetzungen

- IP-Symcon >= 8.2
- Bluelink / Kia Connect Account Instanz (konfiguriert und verbunden)

## Kompatibilität

- Hyundai Bluelink EU (EV und Verbrenner)
- Kia Connect EU (EV und Verbrenner)

## Modul-URL

`https://github.com/da8ter/Bluelink`

## Einstellungen

| Eigenschaft | Typ | Standard | Beschreibung |
|-------------|-----|----------|-------------|
| VIN | string | – | Fahrzeug-Identifikationsnummer (automatisch vom Konfigurator) |
| Vehicle ID | string | – | Interne Bluelink-ID (automatisch) |
| Poll Interval | integer | 300 | Abfrageintervall Cloud in Sekunden (0 = deaktiviert, min. 60 wenn aktiv) |
| Allow Vehicle Refresh | bool | false | Erlaubt echten Fahrzeug-Refresh (weckt Auto, belastet 12V-Batterie) |
| Vehicle Refresh Interval | integer | 0 | Intervall für Fahrzeug-Refresh in Sekunden (0 = deaktiviert, weckt Auto) |
| Refresh On Action | bool | false | Status nach Remote-Aktion automatisch aktualisieren |
| Faster Vehicle Polling While Charging | bool | false | Schnelleres Fahrzeug-Polling während des Ladens |
| Charging Poll Interval | integer | 900 | Fahrzeug-Refresh-Intervall während des Ladens in Sekunden (min. 300) |
| Debug Enabled | bool | false | Debug-Ausgabe aktivieren |

## Variablen

Die Statusvariablen verwenden Symcon-Darstellungen. 

### Status

| Ident | Typ | Profil | Beschreibung |
|-------|-----|--------|-------------|
| DoorsLocked | bool | Enumeration | Türen verriegelt (schaltbar) |
| DoorOpenDriver | bool | Value Presentation | Fahrertür |
| DoorOpenPassenger | bool | Value Presentation | Beifahrertür |
| DoorOpenRearLeft | bool | Value Presentation | Hinten links |
| DoorOpenRearRight | bool | Value Presentation | Hinten rechts |
| TrunkOpen | bool | Value Presentation | Kofferraum |
| HoodOpen | bool | Value Presentation | Motorhaube |

### Fenster

| Ident | Typ | Profil | Beschreibung |
|-------|-----|--------|-------------|
| WindowOpenDriver | bool | Value Presentation | Fahrerfenster |
| WindowOpenPassenger | bool | Value Presentation | Beifahrerfenster |
| WindowOpenRearLeft | bool | Value Presentation | Hinten links |
| WindowOpenRearRight | bool | Value Presentation | Hinten rechts |

### EV & Laden

| Ident | Typ | Profil | Beschreibung |
|-------|-----|--------|-------------|
| SOC | int | Value Presentation | Batterieladung in % |
| RangeKm | float | Value Presentation | Reichweite in km |
| PluggedIn | bool | Value Presentation | Ladekabel angeschlossen |
| ChargingState | int | Value Presentation | 0=Getrennt, 1=Angeschlossen, 2=Lädt |
| ChargingPowerKw | float | Value Presentation | Ladeleistung in kW |
| RemainingChargeTimeMin | int | Value Presentation | Restladezeit in Minuten |
| ChargeLimitAC | int | Slider | AC-Ladelimit in % (schaltbar, 50–100%, Step 10) |
| ChargeLimitDC | int | Slider | DC-Ladelimit in % (schaltbar, 50–100%, Step 10) |

### Klima

| Ident | Typ | Profil | Beschreibung |
|-------|-----|--------|-------------|
| ClimateOn | bool | Switch | Klimaanlage (schaltbar) |
| TargetTempC | float | Slider | Zieltemperatur (schaltbar) |
| Defrost | bool | Value Presentation | Entfrostung |
| SteeringHeat | bool | Value Presentation | Lenkradheizung |

### Laden (Aktion)

| Ident | Typ | Profil | Beschreibung |
|-------|-----|--------|-------------|
| ChargeAction | bool | Switch | Laden starten/stoppen (schaltbar) |

### Standort

| Ident | Typ | Beschreibung |
|-------|-----|-------------|
| Latitude | float | Breitengrad |
| Longitude | float | Längengrad |
| PositionTimestamp | string | Zeitstempel der Position |

### Fahrdaten

| Ident | Typ | Profil | Beschreibung |
|-------|-----|--------|-------------|
| OdometerKm | float | Value Presentation | Kilometerstand |
| FuelLevelPercent | int | Value Presentation | Tankfüllstand (nur Verbrenner/PHEV) |
| Battery12VPercent | int | Value Presentation | 12V-Batterie |

### Meta

| Ident | Typ | Beschreibung |
|-------|-----|-------------|
| LastUpdateTimestamp | string | Letztes Update |
| LastCommandTimestamp | string | Letzter Befehl |
| ApiOnline | bool | API-Verfügbarkeit |
| ErrorText | string | Letzter Fehler |
| CloudRefreshCounter | int | Anzahl Cloud-Statusabfragen |
| VehicleRefreshCounter | int | Anzahl Fahrzeug-Refreshes |

## PHP-Befehle

| Befehl | Beschreibung |
|--------|-------------|
| `BL_UpdateStatus($id)` | Cloud-Status abrufen |
| `BL_RefreshVehicleStatus($id)` | Fahrzeug wecken und Status holen |
| `BL_RefreshLocation($id)` | Standort aktualisieren |
| `BL_Lock($id)` | Verriegeln |
| `BL_Unlock($id)` | Entriegeln |
| `BL_ClimateStart($id, $temperature)` | Klimaanlage starten (Temperatur in °C) |
| `BL_ClimateStop($id)` | Klimaanlage stoppen |
| `BL_ChargeStart($id)` | Laden starten |
| `BL_ChargeStop($id)` | Laden stoppen |
| `BL_SetChargeTargets($id, $limitAC, $limitDC)` | Ladelimits setzen (50–100%) |
