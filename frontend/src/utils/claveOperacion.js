// Clave de idempotencia de una operación (UUID v4).
// crypto.randomUUID solo existe en contextos seguros (HTTPS o localhost); en el servidor del curso (HTTP)
// se arma el UUID v4 con crypto.getRandomValues, que sí está disponible.

export function nuevaClaveOperacion(cripto = globalThis.crypto) {
  if (typeof cripto?.randomUUID === 'function') return cripto.randomUUID()
  const bytes = cripto.getRandomValues(new Uint8Array(16))
  bytes[6] = (bytes[6] & 0x0f) | 0x40 // versión 4
  bytes[8] = (bytes[8] & 0x3f) | 0x80 // variante RFC 4122
  const h = [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('')
  return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`
}

/**
 * ¿Conviene reintentar con la MISMA clave? Solo si el servidor pudo no haber recibido o terminado
 * la operación: sin respuesta (red, tiempo agotado), 409 (la misma operación sigue en curso) o error 5xx.
 * Con cualquier otra respuesta
 * el intento quedó resuelto y el siguiente debe usar una clave nueva.
 */
export function reintentarConMismaClave(falla) {
  return !falla?.estado || falla.estado === 409 || falla.estado >= 500
}
