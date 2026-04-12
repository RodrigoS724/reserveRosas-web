# Web PHP (Reservas)

Esta web expone una interfaz HTML + API simple para:
- Obtener horarios disponibles
- Crear reservas
- Consultar datos de vehículos

## Modos de operación

### 1. Con API Remota (Recomendado)
La web hace proxy a una API Node.js remota. No requiere MySQL local.

**Requisitos:**
- PHP 8.0+
- Acceso HTTP a la API remota (ej: `http://rosas.uy/reserva`)

**Configuración:**
1. Copia `.env.example` a `.env`
2. Completa:
   ```env
   API_REMOTE_URL=http://rosas.uy/reserva
   API_REMOTE_TOKEN=gh2t2oNre50TR4ZucrkssNPFb8LnDhD5JT9gM89ERy4
   ```

### 2. Con MySQL Local (Legado)
La web consulta una base de datos MySQL local.

**Requisitos:**
- PHP 8.0+
- Extensión `pdo_mysql` habilitada
- MySQL/MariaDB

**Configuración:**
1. Copia `.env.example` a `.env`
2. Completa credenciales de MySQL:
   ```env
   MYSQL_HOST=127.0.0.1
   MYSQL_PORT=3306
   MYSQL_USER=tu_usuario
   MYSQL_PASSWORD=tu_password
   MYSQL_DATABASE=reservas_rosas
   ```

## Endpoints

Todos requieren token en `X-API-KEY` header o query param `token=...`.

### GET `/api/horarios?fecha=YYYY-MM-DD`
Devuelve horarios disponibles para la fecha.

**Con API Remota:** Proxy transparente a la API Node remota.  
**Con MySQL:** Consulta la BD local.

### GET `/api/vehiculo?matricula=ABC1234`
Devuelve marca/modelo si el vehículo existe.

**Con API Remota:** Proxy transparente.  
**Con MySQL:** Consulta la BD local.

### POST `/api/reservas`
Crea una nueva reserva.

**Body JSON:**
```json
{
  "nombre": "Juan Perez",
  "cedula": "12345678",
  "telefono": "099123456",
  "marca": "Yamaha",
  "modelo": "FZ",
  "km": "12000",
  "matricula": "ABC1234",
  "tipo_turno": "Particular",
  "particular_tipo": "Service",
  "fecha": "2026-02-04",
  "hora": "10:00",
  "detalles": ""
}
```

## Debug

Para ver configuración actual:
```
GET /api/debug?token=tu_token
```

Mostrará si está usando API remota o MySQL local.
