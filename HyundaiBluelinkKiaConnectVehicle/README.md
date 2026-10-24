# Bluelink Vehicle

## Funktionsumfang

Device-Modul für ein einzelnes Hyundai- oder Kia-Fahrzeug. Pro Fahrzeug wird eine Instanz erstellt (über den Konfigurator).

Funktionen:
- Automatischer Statusabruf (Cloud) alle 300 Sekunden (konfigurierbar)
- Optionaler Fahrzeug-Refresh (weckt das Auto)
- Remote-Aktionen: Verriegeln, Entriegeln, Klimaanlage, Laden
- Standortabfrage
- EV-Daten: Ladezustand, Reichweite, Ladestatus
- Dashboard-Kachel für die Kachel-Visualisierung mit Fernbefehlen, Fahrtenbuch und Statistik
- Fahrstatistik (optional): Tageswerte für Verbrauch je Komponente, Rekuperation und Strecke, als Zähler mit Nachtrag ins Archiv

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
| Dashboard Tile | bool | true | Dashboard-Kachel statt der Variablenliste (siehe unten) |
| Keep Trip Log | integer | 0 | Fahrtenbuch aufbewahren in Tagen, 0 = unbegrenzt |
| Driving Statistics | bool | false | Tageswerte der Fahrstatistik abrufen (siehe unten) |
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

### Dashboard-Kachel

Die Fahrzeug-Instanz bringt eine eigene Kachel für die Kachel-Visualisierung mit. Sie passt sich an Elektroautos, Plug-in-Hybride und Verbrenner an. Welche Bereiche erscheinen, richtet sich nach dem Fahrzeugtyp aus der Fahrzeugliste und den Daten, die das Auto liefert.

- **Übersicht:** Ladezustand mit Reichweite als Ring, beim Verbrenner der Tank; Laden mit Leistung und Restzeit; Ladelimits AC/DC; Verriegeln/Entriegeln (Entriegeln mit Rückfrage), Klima mit Zieltemperatur, Laden starten/stoppen; Türen, Fenster, Kofferraum, Motorhaube, 12-V-Batterie, Kilometerstand; letzte Fahrt; Standort mit Karte. Die Karte öffnet sich in der Kachel und lädt erst beim Antippen von OpenStreetMap; dabei gehen die Koordinaten an OpenStreetMap.
- **Fahrtenbuch:** die Fahrten nach Tagen, zuerst die letzten 31 Tage; „Ältere Fahrten“ lädt das gespeicherte Fahrtenbuch monatsweise nach, je Fahrt Startzeit, Strecke, Fahr- und Standzeit, Durchschnitts- und Höchstgeschwindigkeit. Bei Elektroautos steht am Tag der Verbrauch in kWh und kWh/100 km.
- **Statistik:** Balken der letzten 30 Tage für Strecke, Verbrauch oder kWh/100 km, Aufteilung des Verbrauchs auf Antrieb, Klima, Bordelektronik und Batteriepflege, Rekuperation und Durchschnittswerte.
- **Kleine Kacheln:** In 1×1 nur der Ring mit Schloss, in 2×1 und 2×2 Ring, Laden und die Knöpfe für Schloss und Klima.

Im Kopf steht, wann sich das Auto zuletzt gemeldet hat. Ist das länger als drei Stunden her, kennzeichnet die Kachel Klima und Laden als „zuletzt gemeldet“. Das Fahrtenbuch bewahrt die Instanz in einem versteckten Medienobjekt „Fahrtenbuch“ auf, standardmäßig unbegrenzt (einstellbar); `BL_GetTripLog($id)` liefert es als JSON. Beim ersten Abruf sucht die Instanz einmalig auch ältere Fahrten in der Cloud (Knopf „Ältere Fahrten aus der Cloud nachladen“ im Formular) und lädt sie nach und nach, höchstens 15 Tage pro Stunde. Die Cloud lieferte bei einem Test Fahrten der letzten gut drei Monate. Fernbefehle brauchen die PIN in der Konto-Instanz. Fahrtenbuch und Tageswerte holt die Instanz stündlich zusammen mit der Statusabfrage aus der Cloud; das weckt das Auto nicht. Der Knopf „Fahrzeug wecken“ erscheint nur, wenn der Fahrzeug-Refresh erlaubt ist, und fragt vorher nach.

### Fahrstatistik (optional)

Mit „Tageswerte (Energie je Komponente, Strecke)“ holt die Instanz stündlich zusammen mit der Statusabfrage die Tageswerte der letzten 30 Tage aus der Cloud. Das weckt das Auto nicht. Die Werte gibt es nur bei Fahrzeugen, die sie liefern (Elektro und Plug-in-Hybrid).

| Ident | Typ | Beschreibung |
|-------|-----|-------------|
| StatsDayDate | string | Datum des letzten abgeschlossenen Tags mit Fahrdaten |
| StatsDayConsumption, StatsDayDrive, StatsDayClimate, StatsDayElectronics, StatsDayBatteryCare | float | Verbrauch dieses Tags in kWh: gesamt, Antrieb, Klima, Bordelektronik, Batteriepflege |
| StatsDayRegeneration | float | Rekuperation dieses Tags in kWh |
| StatsDayDistance | float | Strecke dieses Tags in km |
| StatsCounterConsumption … StatsCounterDistance | float | Dieselben Werte als fortlaufende Zähler |

Jeder abgeschlossene Tag wird einmal in die Zähler gebucht. Beim Einschalten trägt die Instanz die letzten 30 Tage mit ihrem Datum ins Archiv nach, danach jeden neuen Tag. Die Zähler werden beim Anlegen geloggt und auf die Archiv-Aggregation „Zähler“ gestellt; Tages-, Wochen- und Monatssummen kommen so direkt aus dem Archiv, auch wenn tagelang nicht abgefragt wurde (bis 30 Tage). Daten, die erst nach dem nächsten gebuchten Tag eintreffen, zählt die Instanz zum Zeitpunkt des Abrufs dazu.

### Meta

| Ident | Typ | Beschreibung |
|-------|-----|-------------|
| LastUpdateTimestamp | string | Letztes Update (Zeit der Cloud-Abfrage) |
| VehicleTimestamp | string | Fahrzeugdaten vom: Zeit der letzten Meldung des Autos. Ein geparktes Auto meldet sich nur selten; ältere Werte für Klima und Laden können überholt sein. |
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
| `BL_UpdateDrivingStats($id)` | Fahrstatistik sofort abrufen |
| `BL_UpdateTrips($id)` | Fahrtenbuch sofort abrufen |
| `BL_GetTripLog($id)` | Ganzes Fahrtenbuch als JSON (Datum => Tag mit Fahrten) |
| `BL_BackfillTrips($id)` | Ältere Fahrten in der Cloud suchen und zum Nachladen vormerken |
