# Bluelink Account

## Funktionsumfang

Splitter-Modul für die Hyundai Bluelink / Kia Connect EU API. Verwaltet:

- Anmeldedaten und OAuth2-Token (Access + Refresh)
- API-Stamps für die EU-Region
- Fahrzeugliste
- Zentraler API-Client für alle Fahrzeug-Instanzen

## Voraussetzungen

- IP-Symcon >= 8.2
- Hyundai Bluelink oder Kia Connect Account (EU)

## Kompatibilität

- Hyundai Bluelink EU
- Kia Connect EU

## Modul-URL

`https://github.com/da8ter/Bluelink`

## Einstellungen

| Eigenschaft | Typ | Beschreibung |
|-------------|-----|-------------|
| Brand | select | Marke (Hyundai / Kia) |
| PIN | string | 4-stelliger PIN für Remote-Aktionen |
| Refresh Token | string | OAuth2 Refresh Token (empfohlen) |
| Debug Enabled | bool | Debug-Ausgabe aktivieren |

> Für beide Marken gleichzeitig: Zwei Account-Instanzen erstellen (eine pro Marke).

## PHP-Befehle

| Befehl | Beschreibung |
|--------|-------------|
| `BL_TestLogin($id)` | Testet die Anmeldung und gibt JSON zurück |
| `BL_LoadVehicles($id)` | Lädt Fahrzeugliste und gibt JSON zurück |
