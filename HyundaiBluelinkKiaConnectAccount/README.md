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
| Username | string | E-Mail-Adresse des Hyundai-/Kia-Kontos |
| Password | password | Passwort des Hyundai-/Kia-Kontos für OneApp/CCI |
| PIN | string | 4-stelliger PIN für Remote-Aktionen |
| Debug Enabled | bool | Debug-Ausgabe aktivieren |

> Für beide Marken gleichzeitig: Zwei Account-Instanzen erstellen (eine pro Marke).

Das Modul meldet sich mit E-Mail und Passwort an, speichert den vollständigen CCI-Token-Satz im Instanzpuffer und erneuert ihn automatisch. Die frühere Refresh-Token-Property bleibt ausschließlich zur Kompatibilität mit bereits konfigurierten Altinstallationen erhalten und wird im Konfigurationsformular nicht mehr angezeigt.

## PHP-Befehle

| Befehl | Beschreibung |
|--------|-------------|
| `BL_TestLogin($id)` | Testet die Anmeldung und gibt JSON zurück |
| `BL_LoadVehicles($id)` | Lädt Fahrzeugliste und gibt JSON zurück |
