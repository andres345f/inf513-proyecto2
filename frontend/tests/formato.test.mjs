// Pruebas de las utilidades de formato de fechas (H-13).
// Ejecutar: npm test   (equivale a: node --test tests/)
// No dependen de la zona horaria del equipo: cada caso se repite con varias zonas del navegador.
import { test } from 'node:test'
import assert from 'node:assert/strict'

const ZONAS_NAVEGADOR = ['America/La_Paz', 'UTC', 'Asia/Tokyo', 'America/Los_Angeles', 'Pacific/Kiritimati']

const { formatearFecha, formatearFechaHora, fechaCivilHoy, sumarDiasCivil, aInstante, esFechaCivil } =
  await import('../src/utils/formato.js')

/** Ejecuta la comprobación con el reloj del proceso en cada zona horaria de la lista. */
function enCadaZona(comprobar) {
  const original = process.env.TZ
  try {
    for (const zona of ZONAS_NAVEGADOR) {
      process.env.TZ = zona
      comprobar(zona)
    }
  } finally {
    if (original === undefined) delete process.env.TZ
    else process.env.TZ = original
  }
}

test('una fecha civil AAAA-MM-DD se muestra el mismo día en cualquier zona', () => {
  enCadaZona((zona) => {
    assert.equal(formatearFecha('2028-04-10'), '10/04/2028', zona)
    assert.equal(formatearFecha('2026-11-23'), '23/11/2026', zona)
    assert.equal(formatearFecha('2026-01-01'), '01/01/2026', zona)
    assert.equal(formatearFecha('2026-12-31'), '31/12/2026', zona)
    // Una fecha civil no tiene hora: formatearFechaHora no inventa una medianoche.
    assert.equal(formatearFechaHora('2027-03-02'), '02/03/2027', zona)
  })
})

test('un instante se presenta con la hora de Bolivia (America/La_Paz)', () => {
  enCadaZona((zona) => {
    // Formato que devuelve PostgreSQL con la conexión en America/La_Paz.
    assert.match(formatearFechaHora('2026-04-18 10:00:00-04'), /^18\/04\/2026,? 10:00/, zona)
    // Mismo instante expresado en UTC.
    assert.match(formatearFechaHora('2026-04-18T14:00:00Z'), /^18\/04\/2026,? 10:00/, zona)
    assert.match(formatearFechaHora('2026-04-18T14:00:00.123456+00:00'), /^18\/04\/2026,? 10:00/, zona)
  })
})

test('instantes cercanos a la medianoche caen en el día correcto de Bolivia', () => {
  enCadaZona((zona) => {
    // 23:30 en Bolivia = 03:30 UTC del día siguiente: sigue siendo 23/11 en Bolivia.
    assert.equal(formatearFecha('2026-11-23 23:30:00-04'), '23/11/2026', zona)
    assert.equal(formatearFecha('2026-11-24T03:30:00Z'), '23/11/2026', zona)
    // 00:15 en Bolivia = 04:15 UTC del mismo día.
    assert.equal(formatearFecha('2026-11-24 00:15:00-04'), '24/11/2026', zona)
    assert.equal(formatearFecha('2026-11-24T04:15:00Z'), '24/11/2026', zona)
    assert.match(formatearFechaHora('2026-11-24T03:59:00Z'), /^23\/11\/2026,? 11:59/, zona)
    // Fin de año.
    assert.equal(formatearFecha('2027-01-01T03:59:59Z'), '31/12/2026', zona)
  })
})

test('una marca sin desplazamiento se interpreta como hora de Bolivia', () => {
  enCadaZona((zona) => {
    assert.equal(aInstante('2026-11-23 23:30:00').toISOString(), '2026-11-24T03:30:00.000Z', zona)
    assert.equal(formatearFecha('2026-11-23 23:30'), '23/11/2026', zona)
  })
})

test('valores vacíos o inválidos no rompen la pantalla', () => {
  for (const valor of [null, undefined, '', 'no es fecha', '2026-13-45']) {
    assert.equal(formatearFecha(valor), '—', String(valor))
    assert.equal(formatearFechaHora(valor), '—', String(valor))
  }
})

test('esFechaCivil distingue fechas de instantes', () => {
  assert.equal(esFechaCivil('2026-11-23'), true)
  assert.equal(esFechaCivil('2026-11-23 10:00:00-04'), false)
  assert.equal(esFechaCivil('2026-11-23T10:00:00Z'), false)
  assert.equal(esFechaCivil(null), false)
})

test('fechaCivilHoy usa el calendario de Bolivia, no el del equipo', () => {
  enCadaZona((zona) => {
    // 24/11/2026 02:00 UTC = 23/11/2026 22:00 en Bolivia.
    assert.equal(fechaCivilHoy(new Date('2026-11-24T02:00:00Z')), '2026-11-23', zona)
    // 24/11/2026 05:00 UTC = 24/11/2026 01:00 en Bolivia.
    assert.equal(fechaCivilHoy(new Date('2026-11-24T05:00:00Z')), '2026-11-24', zona)
  })
})

test('sumarDiasCivil cruza meses y años sin desfase', () => {
  enCadaZona((zona) => {
    assert.equal(sumarDiasCivil('2026-11-24', -6), '2026-11-18', zona)
    assert.equal(sumarDiasCivil('2026-03-01', -1), '2026-02-28', zona)
    assert.equal(sumarDiasCivil('2028-03-01', -1), '2028-02-29', zona)
    assert.equal(sumarDiasCivil('2026-12-31', 1), '2027-01-01', zona)
  })
})
