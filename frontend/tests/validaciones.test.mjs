// Pruebas de la validación propia de los formularios (H-26): mensajes en español, sin la validación del navegador.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { atributosError, erroresDeApi, fechaValida, noAnteriorA, noMayorQueCampo, numero, validar } from '../src/utils/validaciones.js'
import { reglasLote, reglasProduccion } from '../src/pages/productor/reglasProductor.js'

test('numero: límites, enteros y decimales', () => {
  const cantidad = numero({ mayorQue: 0, decimales: 3 }, 'mal')
  assert.equal(cantidad(''), null)
  assert.equal(cantidad('2,5'), null)
  assert.equal(cantidad('0'), 'mal')
  assert.equal(cantidad('-1'), 'mal')
  assert.equal(cantidad('1.2345'), 'mal')
  assert.equal(cantidad('abc'), 'mal')
  const unidades = numero({ mayorQue: 0, entero: true }, 'entero')
  assert.equal(unidades('3'), null)
  assert.equal(unidades('3.5'), 'entero')
})

test('fechas: válidas y orden entre campos', () => {
  assert.equal(fechaValida('mal')('2026-02-30'), 'mal')
  assert.equal(fechaValida('mal')('2028-02-29'), null)
  const regla = noAnteriorA('inicio', 'antes')
  assert.equal(regla('2026-12-09', { inicio: '2026-12-10' }), 'antes')
  assert.equal(regla('2026-12-10', { inicio: '2026-12-10' }), null)
  assert.equal(noMayorQueCampo('producida', 'mas')('11', { producida: '10' }), 'mas')
})

test('producción vacía: un mensaje propio por cada campo obligatorio', () => {
  const errores = validar({ producto_id: '', fecha_inicio: '', fecha_disponibilidad_estimada: '', cantidad_estimada: '', ubicacion_produccion: '', observacion: '' }, reglasProduccion(true))
  assert.deepEqual(Object.keys(errores).sort(), ['cantidad_estimada', 'fecha_disponibilidad_estimada', 'fecha_inicio', 'producto_id', 'ubicacion_produccion'])
  assert.equal(errores.producto_id, 'Elige el producto que estás produciendo.')
})

test('producción: disponibilidad anterior al inicio y cantidad no entera', () => {
  const errores = validar({
    producto_id: '3', fecha_inicio: '2026-12-10', fecha_disponibilidad_estimada: '2026-12-01',
    cantidad_estimada: '2.5', ubicacion_produccion: 'Parcela norte', observacion: '',
  }, reglasProduccion(true))
  assert.equal(errores.fecha_disponibilidad_estimada, 'La disponibilidad no puede ser anterior al inicio.')
  assert.match(errores.cantidad_estimada, /entero/)
})

test('lote: vencimiento sin elaboración, oferta mayor que lo producido y menor que lo comprometido', () => {
  const base = { fecha_elaboracion: '', fecha_vencimiento: '2027-01-01', cantidad_producida: '10', cantidad_ofrecida: '12', precio_ofrecido: '' }
  const errores = validar(base, reglasLote(true, 0))
  assert.match(errores.fecha_elaboracion, /elaboración/)
  assert.equal(errores.cantidad_ofrecida, 'No puedes ofrecer más de lo producido.')
  const comprometido = validar({ ...base, fecha_elaboracion: '2026-11-01', cantidad_ofrecida: '2' }, reglasLote(true, 5))
  assert.match(comprometido.cantidad_ofrecida, /comprometido/)
})

test('errores de la API y atributos accesibles', () => {
  assert.deepEqual(erroresDeApi({ errores: { motivo: ['Escribe el motivo.'] } }), { motivo: 'Escribe el motivo.' })
  assert.deepEqual(atributosError({ motivo: 'x' }, 'motivo', 'm-error'), { 'aria-invalid': true, 'aria-describedby': 'm-error' })
  assert.deepEqual(atributosError({}, 'motivo', 'm-error'), { 'aria-invalid': undefined, 'aria-describedby': undefined })
})
