// Gráficos de ingresos del panel (hallazgo 9 de la revisión intermedia): los importes son netos (cobrado − reintegrado)
// y pueden ser negativos. Un medio o un mes con más reintegros que cobros tuvo movimientos: no es «Sin registros».
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import {
  DOMINIO_CON_CERO, filasPorMetodo, metaPorMes, metaPorMetodo, resumenPorMetodo, signoImporte,
} from '../src/utils/panelNegocio.js'

const ETIQUETAS = { QR: 'QR', TARJETA: 'Tarjeta', EFECTIVO: 'Efectivo' }
const fuente = (ruta) => readFileSync(new URL(`../src/${ruta}`, import.meta.url), 'utf8')

// Marcas que dibuja la versión instalada de Recharts en el eje de importes de los dos gráficos de ingresos netos.
// Recharts 3 calcula los ejes en efectos de React (renderToStaticMarkup no los dibuja), así que se usa su almacén
// real y su selector selectTicksOfAxis, con las mismas acciones que despachan BarChart, XAxis/YAxis, Bar y
// ReferenceLine. Si una actualización de Recharts cambia estas rutas internas, se ajusta este apoyo, no las marcas.
const interno = (ruta) => createRequire(import.meta.url)(`recharts/lib/${ruta}`)
const { createRechartsStore } = interno('state/store.js')
const { addXAxis, addYAxis } = interno('state/cartesianAxisSlice.js')
const { setChartData } = interno('state/chartDataSlice.js')
const { addCartesianGraphicalItem } = interno('state/graphicalItemsSlice.js')
const { addLine } = interno('state/referenceElementsSlice.js')
const { setChartSize, setLayout } = interno('state/layoutSlice.js')
const { selectTicksOfAxis } = interno('state/selectors/axisSelectors.js')
const { xAxisDefaultProps } = interno('cartesian/XAxis.js')
const { yAxisDefaultProps } = interno('cartesian/YAxis.js')
const { referenceLineDefaultProps } = interno('cartesian/ReferenceLine.js')

// Eje de importes y línea en 0 tal como están en PanelPagina.jsx (la última prueba comprueba que el panel los usa).
const EJE_IMPORTES = { type: 'number', domain: DOMINIO_CON_CERO }
const LINEA_EN_CERO = { ifOverflow: 'extendDomain' }

/** layout 'horizontal': «Ingresos netos por mes» (importes en el eje Y); 'vertical': «… por medio de pago» (eje X). */
function marcasDelEje(layout, importes) {
  const almacen = createRechartsStore(undefined, 'BarChart')
  const enY = layout === 'horizontal'
  const categoria = { type: 'category', dataKey: 'nombre' }
  const ajustes = (defecto, idProp, props) => {
    const { [idProp]: id, ...resto } = { ...defecto, ...props }
    return { ...resto, id }
  }
  almacen.dispatch(setLayout(layout))
  almacen.dispatch(setChartSize({ width: 600, height: 300 }))
  almacen.dispatch(setChartData(importes.map((monto, i) => ({ nombre: `fila ${i}`, monto }))))
  almacen.dispatch(addXAxis(ajustes(xAxisDefaultProps, 'xAxisId', enY ? categoria : EJE_IMPORTES)))
  almacen.dispatch(addYAxis(ajustes(yAxisDefaultProps, 'yAxisId', enY ? EJE_IMPORTES : categoria)))
  almacen.dispatch(addCartesianGraphicalItem({
    type: 'bar', id: 'barras', xAxisId: 0, yAxisId: 0, zAxisId: 0, dataKey: 'monto', hide: false, minPointSize: 0, isPanorama: false, hasCustomShape: false,
  }))
  const { xAxisId, yAxisId, ifOverflow } = referenceLineDefaultProps
  almacen.dispatch(addLine({ xAxisId, yAxisId, ifOverflow, ...LINEA_EN_CERO, ...(enY ? { y: 0 } : { x: 0 }) }))
  return (selectTicksOfAxis(almacen.getState(), enY ? 'yAxis' : 'xAxis', 0, false) ?? []).map((marca) => marca.value)
}
const GRAFICOS = { horizontal: 'ingresos por mes', vertical: 'ingresos por medio de pago' }

// Respuesta real de GET /api/panel/negocio?desde=2026-07-04&hasta=2026-07-31 (solo el reintegro de PED-2026-0003).
const SOLO_REINTEGRO = [{ metodo: 'QR', monto: -76, cobrado: 0, reintegrado: 76, cantidad: 0 }]

test('reproducción: un periodo con solo un reintegro QR muestra el medio con su neto negativo', () => {
  const filas = filasPorMetodo(SOLO_REINTEGRO, ETIQUETAS)
  assert.deepEqual(filas, [{ metodo: 'QR', nombre: 'QR', monto: -76, cobrado: 0, reintegrado: 76, cantidad: 0 }])
  assert.equal(metaPorMetodo(filas), '1 medio con movimientos')
  assert.equal(resumenPorMetodo(filas), 'QR: neto Bs -76,00 (cobrado Bs 0,00, reintegrado Bs 76,00)')
})

test('no se descartan netos en cero ni negativos; «Sin registros» solo sin movimientos', () => {
  const filas = filasPorMetodo([
    { metodo: 'QR', monto: 951.5, cobrado: 1027.5, reintegrado: 76, cantidad: 5 },
    { metodo: 'TARJETA', monto: 0, cobrado: 180, reintegrado: 180, cantidad: 1 },
    { metodo: 'EFECTIVO', monto: '-20.50', cobrado: '0', reintegrado: '20.5', cantidad: '0' },
  ], ETIQUETAS)
  assert.deepEqual(filas.map((f) => [f.nombre, f.monto]), [['QR', 951.5], ['Tarjeta', 0], ['Efectivo', -20.5]])
  assert.equal(metaPorMetodo(filas), '3 medios con movimientos')
  assert.equal(metaPorMetodo(filasPorMetodo([], ETIQUETAS)), 'Sin registros')
  assert.equal(metaPorMetodo(filasPorMetodo(undefined, ETIQUETAS)), 'Sin registros')
  // Un medio desconocido conserva su código como nombre.
  assert.equal(filasPorMetodo([{ metodo: 'OTRO', monto: 1 }], ETIQUETAS)[0].nombre, 'OTRO')
})

test('meses: el texto cuenta meses con movimientos, también los de neto negativo', () => {
  assert.equal(metaPorMes([{ mes: '2026-07', total: -76 }]), '1 mes con registros')
  assert.equal(metaPorMes([{ mes: '2026-07', total: -76 }, { mes: '2026-08', total: 10 }]), '2 meses con registros')
  assert.equal(metaPorMes([]), 'Sin registros')
})

// Ciclo de verificación: antes, el dominio fijo (sin «auto») hacía que Recharts no redondeara las marcas
// (julio: −76, −56, −36, −16, 0; periodo mixto: −76, 9, 94, 179, 258, sin marca en 0).
test('reproducción: los ejes de importes tienen marcas redondas, con el cero incluido', () => {
  const casos = [
    ['julio 2026, del 04 al 31: solo el reintegro QR', [-76], [-80, -60, -40, -20, 0]],
    ['año 2026 por mes (máximo 386)', [219, 386], [0, 100, 200, 300, 400]],
    ['año 2026 por medio de pago (máximo 733,5)', [386, 733.5], [0, 200, 400, 600, 800]],
    ['periodo mixto (−76 a 258)', [-76, 258], [-90, 0, 90, 180, 270]],
  ]
  for (const [layout, grafico] of Object.entries(GRAFICOS)) {
    for (const [caso, importes, esperadas] of casos) {
      assert.deepEqual(marcasDelEje(layout, importes), esperadas, `${grafico} · ${caso}`)
    }
  }
})

test('con cualquier signo, el eje de importes incluye el cero y sus marcas son equidistantes y redondas', () => {
  const importesDePrueba = [[-76], [-0.5], [12.25], [0], [5, 5], [-76, -20], [-1234.5, 99], [-218, 1064, 350], [1598.5]]
  for (const [layout, grafico] of Object.entries(GRAFICOS)) {
    for (const importes of importesDePrueba) {
      const marcas = marcasDelEje(layout, importes)
      const contexto = `${grafico} · ${JSON.stringify(importes)} → ${JSON.stringify(marcas)}`
      const paso = marcas[1] - marcas[0]
      assert.ok(marcas.length >= 2 && paso > 0, `al menos dos marcas crecientes: ${contexto}`)
      assert.ok(marcas.includes(0), `marca en 0: ${contexto}`)
      assert.ok(marcas[0] <= Math.min(0, ...importes) && marcas.at(-1) >= Math.max(0, ...importes), `cubre los importes: ${contexto}`)
      marcas.forEach((marca, i) => {
        assert.ok(Math.abs(marca - (marcas[0] + i * paso)) < 1e-9, `equidistantes: ${contexto}`)
        assert.ok(Math.abs(marca / paso - Math.round(marca / paso)) < 1e-9, `múltiplos del paso (no el importe exacto): ${contexto}`)
      })
    }
  }
})

test('signo de un importe para resaltar los negativos', () => {
  assert.equal(signoImporte(-0.01), 'negativo')
  assert.equal(signoImporte(0), 'cero')
  assert.equal(signoImporte('12.5'), 'positivo')
  assert.equal(signoImporte(undefined), 'cero')
})

test('el panel no filtra los medios con neto ≤ 0 y usa un eje con el cero', () => {
  const panel = fuente('pages/panel/PanelPagina.jsx')
  assert.doesNotMatch(panel, /\.filter\(\(p\) => p\.monto > 0\)/, 'no se deben ocultar los medios con neto ≤ 0')
  assert.match(panel, /filasPorMetodo\(d\.pagos_por_metodo, METODOS_PAGO\)/)
  assert.match(panel, /meta=\{metaPorMetodo\(porMetodo\)\}/)
  assert.match(panel, /vacio=\{porMetodo\.length === 0\}/)
  // Los dos gráficos de importes netos admiten negativos: eje con el cero y línea de referencia en 0.
  assert.equal((panel.match(/domain=\{DOMINIO_CON_CERO\}/g) ?? []).length, 2)
  assert.equal((panel.match(/<ReferenceLine /g) ?? []).length, 2)
  // La línea en 0 amplía el dominio (ifOverflow="extendDomain"): así el cero entra aunque todos los netos sean negativos.
  assert.match(panel, /<ReferenceLine y=\{0\} ifOverflow="extendDomain" /, 'línea en 0 del gráfico por mes')
  assert.match(panel, /<ReferenceLine x=\{0\} ifOverflow="extendDomain" /, 'línea en 0 del gráfico por medio de pago')
  // El gráfico circular no puede dibujar importes negativos: ya no se usa para los medios de pago.
  assert.doesNotMatch(panel, /dataKey="monto" nameKey="nombre" outerRadius/)
})
