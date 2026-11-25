import { useState } from 'react'
import { Bar, BarChart, CartesianGrid, Cell, Legend, Pie, PieChart, ReferenceLine, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { estadisticasApi } from '../../api/estadisticasApi'
import RangoFechas from '../../components/panel/RangoFechas'
import SeccionAccesos from '../../components/panel/SeccionAccesos'
import Alerta from '../../components/ui/Alerta'
import Boton from '../../components/ui/Boton'
import Cargando from '../../components/ui/Cargando'
import { useCargaApi } from '../../hooks/useCargaApi'
import { useColoresTema } from '../../hooks/useColoresTema'
import { useSesion } from '../../hooks/useSesion'
import { ESTADOS_PEDIDO, METODOS_PAGO } from '../../utils/etiquetas'
import { formatearFecha, formatearMoneda, formatearNumero } from '../../utils/formato'
import {
  DOMINIO_CON_CERO, filasPorMetodo, metaPorMes, metaPorMetodo, resumenPorMetodo, signoImporte,
} from '../../utils/panelNegocio'
import './PanelPagina.css'

const nombreMes = (mes) => new Intl.DateTimeFormat('es-BO', { month: 'short', year: 'numeric' }).format(new Date(`${mes}-15T12:00:00`))
const formatoEscala = new Intl.NumberFormat('es-BO', { maximumFractionDigits: 0 })

// detalle: contenido accesible y visible bajo el gráfico (por ejemplo, una tabla con las cifras exactas).
function PanelGrafico({ id, titulo, meta, resumen, vacio, vacioTexto, caption, detalle, clase = '', children }) {
  return (
    <section className={`panel__visual ${clase}`.trim()} aria-labelledby={id}>
      <header className="panel__visual-cabecera">
        <div>
          <h2 id={id}>{titulo}</h2>
        </div>
        {meta && <p className="panel__visual-meta">{meta}</p>}
      </header>
      {vacio ? <p className="panel__grafico-vacio">{vacioTexto}</p> : (
        <figure className="panel__grafico">
          <div className="panel__grafico-lienzo" aria-hidden="true">{children}</div>
          {detalle ?? <details className="panel__datos-grafico"><summary>Ver cifras de {titulo.toLocaleLowerCase('es')}</summary><ul>{resumen.map((fila) => <li key={fila}>{fila}</li>)}</ul></details>}
          {caption && <figcaption>{caption}</figcaption>}
        </figure>
      )}
    </section>
  )
}

function Kpi({ etiqueta, valor, nota, tono = '' }) {
  return (
    <div className={`panel__kpi ${tono ? `panel__kpi--${tono}` : ''}`.trim()}>
      <dt>{etiqueta}</dt>
      <dd>{valor}</dd>
      <small>{nota}</small>
    </div>
  )
}

function ResumenNegocio({ indicadores }) {
  const stockBajo = indicadores.productos_stock_bajo > 0
  return (
    <section className="panel__resumen" aria-labelledby="panel-resumen-titulo">
      <article className="panel__destacado">
        <div className="panel__destacado-principal">
          <p className="panel__eyebrow">Ingresos cobrados (netos)</p>
          <h2 id="panel-resumen-titulo">{formatearMoneda(indicadores.ventas)}</h2>
        </div>
        <div className="panel__destacado-detalle">
          <p>Pagos confirmados en el periodo menos los reintegros ejecutados (pedidos cancelados y devoluciones).</p>
          <p>Cobrado {formatearMoneda(indicadores.ventas_brutas ?? indicadores.ventas)} · Reintegrado {formatearMoneda(indicadores.reintegros ?? 0)}</p>
        </div>
      </article>
      <dl className="panel__kpis">
        <Kpi etiqueta="Pedidos vendidos" valor={formatearNumero(indicadores.pedidos)} nota="Confirmados; no cuenta pendientes de pago ni cancelados" />
        <Kpi etiqueta="Ticket promedio" valor={formatearMoneda(indicadores.ticket_promedio)} nota="Total medio por pedido vendido" />
        <Kpi etiqueta="Clientes nuevos" valor={formatearNumero(indicadores.clientes_nuevos)} nota="Registrados en el periodo" />
        <Kpi etiqueta="Pedidos en curso" valor={formatearNumero(indicadores.pedidos_en_curso)} nota="Requieren seguimiento operativo" />
        <Kpi etiqueta="Productos con stock bajo" valor={formatearNumero(indicadores.productos_stock_bajo)}
          nota={stockBajo ? 'Revisar inventario disponible' : 'Sin alertas de inventario'} tono={stockBajo ? 'alerta' : ''} />
      </dl>
    </section>
  )
}

/** Cifras exactas por medio de pago: cobrado, reintegrado y neto (que puede ser negativo). */
function TablaPorMetodo({ filas }) {
  return (
    <div className="panel__tabla-contenedor" tabIndex={0} role="region" aria-label="Cobros y reintegros por medio de pago">
      <table className="panel__tabla">
        <caption className="solo-lector">Cobrado, reintegrado e ingreso neto por medio de pago</caption>
        <thead>
          <tr><th scope="col">Medio</th><th scope="col">Cobrado</th><th scope="col">Reintegrado</th><th scope="col">Neto</th></tr>
        </thead>
        <tbody>
          {filas.map((f) => (
            <tr key={f.metodo}>
              <th scope="row">{f.nombre}</th>
              <td>{formatearMoneda(f.cobrado)}</td>
              <td>{formatearMoneda(f.reintegrado)}</td>
              <td className={`panel__importe panel__importe--${signoImporte(f.monto)}`}>{formatearMoneda(f.monto)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

/** Panel de negocio y estadísticas para Personal FEDER y Administrador. */
export default function PanelPagina({ titulo = 'Panel' }) {
  const { tienePermiso } = useSesion()
  const [rango, setRango] = useState({ desde: '', hasta: '' })
  const filtros = { desde: rango.desde || undefined, hasta: rango.hasta || undefined }
  const negocio = useCargaApi((signal) => estadisticasApi.negocio(filtros, { signal }), [rango.desde, rango.hasta])
  const verAccesos = tienePermiso('accesos.ver')
  const accesos = useCargaApi((signal) => (verAccesos ? estadisticasApi.accesos(filtros, { signal }) : Promise.resolve(null)), [rango.desde, rango.hasta, verAccesos])
  const colores = useColoresTema()

  if (negocio.cargando && !negocio.datos) return <Cargando texto="Calculando indicadores…" />
  if (negocio.error && !negocio.datos) return <Alerta tipo="error"><p>{negocio.error}</p><Boton variante="secundario" onClick={negocio.recargar}>Reintentar</Boton></Alerta>

  const d = negocio.datos
  const ejes = { fill: colores['texto-suave'], fontSize: 12 }
  const paleta = [colores.primario, colores.acento, colores.info, colores.aviso, colores.error, colores.exito, colores.texto]
  const globo = { backgroundColor: colores.superficie, color: colores.texto, border: `1px solid ${colores['texto-suave']}`, borderRadius: 6 }
  const ventasPorMes = d.ventas_por_mes.map((m) => ({ ...m, etiqueta: nombreMes(m.mes) }))
  const pedidosPorEstado = d.pedidos_por_estado.map((e) => ({ ...e, nombre: ESTADOS_PEDIDO[e.estado] ?? e.estado }))
  const productosMasVendidos = d.productos_mas_vendidos
  // Ingresos netos de reintegros: pueden ser cero o negativos y se muestran igual (barras bajo cero y tabla).
  const porMetodo = filasPorMetodo(d.pagos_por_metodo, METODOS_PAGO)
  const colorImporte = (valor) => (signoImporte(valor) === 'negativo' ? colores.error : colores.primario)

  return (
    <section className="panel" aria-labelledby="panel-titulo">
      <header className="panel__cabecera">
        <div>
          <h1 id="panel-titulo">{titulo}</h1>
          <p className="panel__descripcion">Actividad comercial y señales que requieren atención del equipo.</p>
        </div>
        <div className="panel__periodo">
          <p>Periodo aplicado · {formatearFecha(d.rango.desde)} – {formatearFecha(d.rango.hasta)}</p>
          <RangoFechas desde={rango.desde} hasta={rango.hasta} alCambiar={setRango} />
        </div>
      </header>

      {negocio.error && <Alerta tipo="aviso">No se pudo actualizar el periodo. Mostrando el último resultado disponible.</Alerta>}
      {negocio.actualizando && <p role="status">Actualizando…</p>}
      <ResumenNegocio indicadores={d.indicadores} />

      <div className="panel__graficos">
        <PanelGrafico id="panel-ventas-mes" titulo="Ingresos netos por mes"
          meta={metaPorMes(ventasPorMes)}
          vacio={ventasPorMes.length === 0} vacioTexto="No hay cobros ni reintegros en el periodo seleccionado."
          resumen={ventasPorMes.map((m) => `${m.etiqueta}: ${formatearMoneda(m.total)}`)}
          caption="Pagos confirmados menos reintegros de cada mes, en bolivianos. Un mes con más reintegros que cobros queda bajo cero."
          clase="panel__visual--ancho">
          <ResponsiveContainer width="100%" height="100%">
            <BarChart accessibilityLayer={false} data={ventasPorMes} margin={{ top: 8, right: 8, left: 4, bottom: 4 }}>
              <CartesianGrid vertical={false} stroke={colores.borde} strokeDasharray="2 4" />
              <XAxis dataKey="etiqueta" axisLine={false} tickLine={false} tick={ejes} />
              <YAxis axisLine={false} tickLine={false} width={72} tick={ejes} domain={DOMINIO_CON_CERO}
                tickFormatter={(valor) => `Bs ${formatoEscala.format(valor)}`} />
              <ReferenceLine y={0} ifOverflow="extendDomain" stroke={colores['texto-suave']} />
              <Tooltip contentStyle={globo} itemStyle={{ color: colores.texto }} cursor={{ fill: colores['primario-suave'] }} formatter={(valor) => [formatearMoneda(valor), 'Ingresos netos']} />
              <Bar isAnimationActive={false} dataKey="total" fill={colores.primario} maxBarSize={56}>
                {ventasPorMes.map((m) => <Cell key={m.mes} fill={colorImporte(m.total)} />)}
              </Bar>
            </BarChart>
          </ResponsiveContainer>
        </PanelGrafico>

        <PanelGrafico id="panel-pedidos-estado" titulo="Pedidos por estado"
          meta={pedidosPorEstado.length ? `${formatearNumero(d.indicadores.pedidos)} vendidos` : 'Sin registros'}
          vacio={pedidosPorEstado.length === 0} vacioTexto="No hay pedidos para distribuir por estado."
          resumen={pedidosPorEstado.map((e) => `${e.nombre}: ${e.cantidad}`)}>
          <ResponsiveContainer width="100%" height="100%">
            <PieChart accessibilityLayer={false}>
              <Pie isAnimationActive={false} data={pedidosPorEstado} dataKey="cantidad" nameKey="nombre" innerRadius="48%" outerRadius="75%" paddingAngle={2}>
                {pedidosPorEstado.map((e, i) => <Cell key={e.estado} fill={paleta[i % paleta.length]} />)}
              </Pie>
              <Tooltip contentStyle={globo} itemStyle={{ color: colores.texto }} formatter={(valor, nombre) => [formatearNumero(valor), nombre]} />
              <Legend />
            </PieChart>
          </ResponsiveContainer>
        </PanelGrafico>

        <PanelGrafico id="panel-productos-vendidos" titulo="Productos más vendidos"
          meta={productosMasVendidos.length ? `${productosMasVendidos.length} productos` : 'Sin registros'}
          vacio={productosMasVendidos.length === 0} vacioTexto="No hay productos vendidos en el periodo seleccionado."
          resumen={productosMasVendidos.map((p) => `${p.nombre}: ${formatearMoneda(p.monto)}`)}
          caption="Importe en pedidos vendidos (confirmados); no descuenta devoluciones.">
          <ResponsiveContainer width="100%" height="100%">
            <BarChart accessibilityLayer={false} data={productosMasVendidos} layout="vertical" margin={{ left: 12, right: 8 }}>
              <CartesianGrid horizontal={false} stroke={colores.borde} strokeDasharray="2 4" />
              <XAxis type="number" axisLine={false} tickLine={false} tick={ejes}
                tickFormatter={(valor) => `Bs ${formatoEscala.format(valor)}`} />
              <YAxis type="category" dataKey="nombre" width={122} axisLine={false} tickLine={false} tick={ejes} />
              <Tooltip contentStyle={globo} itemStyle={{ color: colores.texto }} formatter={(valor) => [formatearMoneda(valor), 'Vendido']} />
              <Bar isAnimationActive={false} dataKey="monto" fill={colores.acento} radius={[0, 4, 4, 0]} maxBarSize={26} />
            </BarChart>
          </ResponsiveContainer>
        </PanelGrafico>

        <PanelGrafico id="panel-pagos-metodo" titulo="Ingresos netos por medio de pago"
          meta={metaPorMetodo(porMetodo)}
          vacio={porMetodo.length === 0} vacioTexto="No hay cobros ni reintegros por medio de pago en el periodo seleccionado."
          resumen={resumenPorMetodo(porMetodo)}
          caption="Cobros confirmados menos reintegros del pago original. Un neto negativo indica que en el periodo se reintegró más de lo que se cobró."
          detalle={<TablaPorMetodo filas={porMetodo} />}>
          <ResponsiveContainer width="100%" height="100%">
            <BarChart accessibilityLayer={false} data={porMetodo} layout="vertical" margin={{ left: 12, right: 8 }}>
              <CartesianGrid horizontal={false} stroke={colores.borde} strokeDasharray="2 4" />
              <XAxis type="number" axisLine={false} tickLine={false} tick={ejes} domain={DOMINIO_CON_CERO}
                tickFormatter={(valor) => `Bs ${formatoEscala.format(valor)}`} />
              <YAxis type="category" dataKey="nombre" width={72} axisLine={false} tickLine={false} tick={ejes} />
              <ReferenceLine x={0} ifOverflow="extendDomain" stroke={colores['texto-suave']} />
              <Tooltip contentStyle={globo} itemStyle={{ color: colores.texto }} formatter={(valor) => [formatearMoneda(valor), 'Ingreso neto']} />
              <Bar isAnimationActive={false} dataKey="monto" fill={colores.primario} maxBarSize={26}>
                {porMetodo.map((f) => <Cell key={f.metodo} fill={colorImporte(f.monto)} />)}
              </Bar>
            </BarChart>
          </ResponsiveContainer>
        </PanelGrafico>
      </div>

      {verAccesos && accesos.datos && <SeccionAccesos datos={accesos.datos} />}
      {accesos.error && <Alerta tipo="aviso">No se pudieron cargar los accesos del sitio para este periodo.</Alerta>}
    </section>
  )
}
