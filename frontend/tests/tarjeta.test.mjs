// Vencimiento de la tarjeta según el calendario de Bolivia (America/La_Paz), sin depender de la zona del navegador.
// Una tarjeta MM/AAAA vale hasta el último día de ese mes en Bolivia. Cada caso fija el reloj (Date simulado)
// y se repite con el proceso en varias zonas horarias.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { validarTarjeta } from '../src/utils/tarjeta.js'

const ZONAS_NAVEGADOR = ['America/La_Paz', 'UTC', 'Asia/Tokyo', 'America/Los_Angeles', 'Pacific/Kiritimati']
const TARJETA = { numero: '4242 4242 4242 4242', titular: 'Daniela Flores', cvv: '123', alias: '' }

/** Errores de vencimiento (mes y año) de una tarjeta MM/AAAA con el reloj en `instante` y el proceso en cada zona. */
function enCadaZona(t, instante, comprobar) {
  const original = process.env.TZ
  t.mock.timers.enable({ apis: ['Date'], now: new Date(instante) })
  try {
    for (const zona of ZONAS_NAVEGADOR) {
      process.env.TZ = zona
      comprobar(zona)
    }
  } finally {
    t.mock.timers.reset()
    if (original === undefined) delete process.env.TZ
    else process.env.TZ = original
  }
}

function erroresVencimiento(mes, anio) {
  const { expira_mes: errorMes, expira_anio: errorAnio } = validarTarjeta({ ...TARJETA, expira_mes: String(mes), expira_anio: String(anio) })
  return { errorMes, errorAnio }
}

const SIN_ERRORES = { errorMes: undefined, errorAnio: undefined }

test('el 30/11 a las 21:30 en Bolivia (01/12 01:30 UTC) una tarjeta 11/2026 sigue vigente en cualquier zona', (t) => {
  enCadaZona(t, '2026-12-01T01:30:00Z', (zona) => {
    assert.deepEqual(erroresVencimiento(11, 2026), SIN_ERRORES, zona)
    assert.deepEqual(erroresVencimiento(12, 2026), SIN_ERRORES, zona)
  })
})

test('la tarjeta vence al terminar su mes en Bolivia, no un minuto antes', (t) => {
  // 30/11/2026 23:59:30 en Bolivia = 01/12/2026 03:59:30 UTC.
  enCadaZona(t, '2026-12-01T03:59:30Z', (zona) => {
    assert.deepEqual(erroresVencimiento(11, 2026), SIN_ERRORES, zona)
  })
})

test('desde el 01/12 a las 00:30 en Bolivia una tarjeta 11/2026 está vencida en cualquier zona', (t) => {
  enCadaZona(t, '2026-12-01T04:30:00Z', (zona) => {
    assert.equal(erroresVencimiento(11, 2026).errorMes, 'La tarjeta está vencida.', zona)
    assert.equal(erroresVencimiento(10, 2026).errorMes, 'La tarjeta está vencida.', zona)
    assert.deepEqual(erroresVencimiento(12, 2026), SIN_ERRORES, zona)
    assert.deepEqual(erroresVencimiento(1, 2027), SIN_ERRORES, zona)
  })
})

test('el año actual es el de Bolivia: el 31/12 a las 23:30 una tarjeta 12/2026 vale aunque en UTC ya sea 2027', (t) => {
  enCadaZona(t, '2027-01-01T03:30:00Z', (zona) => {
    assert.deepEqual(erroresVencimiento(12, 2026), SIN_ERRORES, zona)
    assert.equal(erroresVencimiento(11, 2026).errorMes, 'La tarjeta está vencida.', zona)
    assert.equal(erroresVencimiento(12, 2025).errorAnio, 'Año no válido.', zona)
    assert.deepEqual(erroresVencimiento(12, 2041), SIN_ERRORES, zona)
    assert.equal(erroresVencimiento(1, 2042).errorAnio, 'Año no válido.', zona)
  })
})

test('el 01/01 a las 00:30 en Bolivia una tarjeta 12/2026 ya venció en cualquier zona', (t) => {
  enCadaZona(t, '2027-01-01T04:30:00Z', (zona) => {
    assert.equal(erroresVencimiento(12, 2026).errorAnio, 'Año no válido.', zona)
    assert.deepEqual(erroresVencimiento(1, 2027), SIN_ERRORES, zona)
  })
})

test('mes fuera de rango y año no numérico siguen dando su mensaje', (t) => {
  enCadaZona(t, '2026-11-24T15:00:00Z', (zona) => {
    assert.equal(erroresVencimiento(13, 2027).errorMes, 'Mes entre 1 y 12.', zona)
    assert.equal(erroresVencimiento(0, 2027).errorMes, 'Mes entre 1 y 12.', zona)
    assert.equal(erroresVencimiento(5, 'abc').errorAnio, 'Año no válido.', zona)
  })
})
