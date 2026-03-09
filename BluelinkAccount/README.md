# Bluelink Account

## Funktionsumfang

Splitter-Modul für die Hyundai Bluelink EU API. Verwaltet:

- Anmeldedaten und OAuth2-Token (Access + Refresh)
- API-Stamps für die EU-Region
- Fahrzeugliste
- Zentraler API-Client für alle Fahrzeug-Instanzen

## Voraussetzungen

- IP-Symcon >= 8.2
- Hyundai Bluelink Account (EU)

## Kompatibilität

- Hyundai Bluelink EU

## Modul-URL

`https://github.com/ssp/Bluelink`

## Einstellungen

| Eigenschaft | Typ | Beschreibung |
|-------------|-----|-------------|
| Username | string | Bluelink E-Mail-Adresse |
| Password | string | Bluelink Passwort |
| PIN | string | 4-stelliger PIN für Remote-Aktionen |
| Refresh Token | string | OAuth2 Refresh Token (empfohlen) |
| Region | select | Region (aktuell nur EU) |
| Base URL | string | API-Endpunkt |
| Client ID | string | OAuth2 Client-ID |
| Basic Token | string | OAuth2 Basic-Token |
| Stamp URL | string | URL zur Stamp-Quelle |
| Debug Level | select | Off / Basic / Verbose |

## PHP-Befehle

| Befehl | Beschreibung |
|--------|-------------|
| `BL_TestLogin($id)` | Testet die Anmeldung und gibt JSON zurück |
| `BL_LoadVehicles($id)` | Lädt Fahrzeugliste und gibt JSON zurück |
