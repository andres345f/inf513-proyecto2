// Clave de idempotencia del pago (clave_operacion UUID v4 por intento).
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { webcrypto } from 'node:crypto'
import { nuevaClaveOperacion, reintentarConMismaClave } from '../src/utils/claveOperacion.js'

const UUID_V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/

test('genera UUID v4 distintos, también sin randomUUID (HTTP sin contexto seguro)', () => {
  const sinRandomUuid = { getRandomValues: (b) => webcrypto.getRandomValues(b) }
  const claves = new Set()
  for (let i = 0; i < 200; i++) {
    const a = nuevaClaveOperacion(sinRandomUuid)
    const b = nuevaClaveOperacion(webcrypto)
    assert.match(a, UUID_V4)
    assert.match(b, UUID_V4)
    claves.add(a).add(b)
  }
  assert.equal(claves.size, 400)
})

test('solo se reutiliza la clave si el intento quedó sin resolver', () => {
  assert.equal(reintentarConMismaClave({ estado: 0 }), true)
  assert.equal(reintentarConMismaClave({ estado: 409 }), true)
  assert.equal(reintentarConMismaClave({ estado: 503 }), true)
  assert.equal(reintentarConMismaClave({ estado: 422 }), false)
  assert.equal(reintentarConMismaClave({ estado: 403 }), false)
})
