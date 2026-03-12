# Bluelink Configurator

## Funktionsumfang

Konfigurator-Modul zur Verwaltung der Fahrzeug-Instanzen. Zeigt alle im Bluelink-/Kia-Connect-Account registrierten Fahrzeuge an und ermöglicht das Erstellen/Löschen von Fahrzeug-Instanzen.

Die Marke (Hyundai/Kia) wird automatisch vom verbundenen Account-Modul erkannt und im Instanznamen der erstellten Fahrzeuge verwendet.

## Voraussetzungen

- IP-Symcon >= 8.2
- Bluelink Account Instanz (konfiguriert und verbunden)

## Kompatibilität

- Hyundai Bluelink EU
- Kia Connect EU

## Modul-URL

`https://github.com/da8ter/Bluelink`

## Einstellungen

Der Konfigurator hat keine eigenen Einstellungen. Er liest die Fahrzeugliste automatisch vom verbundenen Account-Modul.

### Spalten

| Spalte | Beschreibung |
|--------|-------------|
| VIN | Fahrzeug-Identifikationsnummer |
| Vehicle ID | Interne Bluelink-ID |
| Name | Fahrzeugname aus Bluelink |
| Model | Modellbezeichnung |
| Year | Modelljahr |
