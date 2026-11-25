import { useEffect, useRef, useState } from 'react'
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { consultasApi } from '../../api/consultasApi'
import { productoresApi } from '../../api/productoresApi'
import Indicador from '../../components/panel/Indicador'
import TarjetaGrafico from '../../components/panel/TarjetaGrafico'
import TablaDatos from '../../components/plantillas/TablaDatos'
import EncabezadoPagina from '../../components/plantillas/EncabezadoPagina'
import Alerta from '../../components/ui/Alerta'
import Cargando from '../../components/ui/Cargando'
import Insignia from '../../components/ui/Insignia'
import Boton from '../../components/ui/Boton'
import { useCargaApi } from '../../hooks/useCargaApi'
import { useColoresTema } from '../../hooks/useColoresTema'
import { cantidadConUnidad } from '../../utils/etiquetas'
import { formatearFecha, formatearMoneda, formatearNumero } from '../../utils/formato'
import './Productor.css'

const ESTADOS = { REGISTRADA: ['Registrada', 'info'], RECIBIDA_PARCIAL: ['Recibida en parte', 'aviso'], RECIBIDA: ['Recibida', 'exito'], CANCELADA: ['Cancelada', 'error'] }
const nombreMes = (mes) => new Intl.DateTimeFormat('es-BO', { month: 'short', year: '2-digit' }).format(new Date(`${mes}-15T12:00:00`))

/**
 * Ventas del productor a FEDER. Con soloIndicadores muestra el resumen y el gráfico (página "Mis indicadores").
 */
export default function MisVentasPagina({ soloIndicadores = false }) {
  const datos = useCargaApi(() => (soloIndicadores ? consultasApi.misIndicadores() : consultasApi.misVentas()), [soloIndicadores])
  const c = useColoresTema()
  const [detalle, setDetalle] = useState(null)
  const [errorDetalle, setErrorDetalle] = useState('')
  const tituloDetalle = useRef(null)
  const botonOrigen = useRef(null)
  useEffect(() => {
    if (detalle && tituloDetalle.current) tituloDetalle.current.focus()
  }, [detalle])
  const ultimaVentaSolicitada = useRef(null)
  const verDetalle = async (id, boton) => {
    botonOrigen.current = boton
    ultimaVentaSolicitada.current = id
    setErrorDetalle('')
    try {
      const respuesta = await productoresApi.detalleVenta(id)
      if (ultimaVentaSolicitada.current === id) setDetalle(respuesta)
    } catch (falla) { if (ultimaVentaSolicitada.current === id) setErrorDetalle(falla.mensaje) }
  }

  if (datos.cargando) return <Cargando />
  if (datos.error) return <Alerta tipo="error">{datos.error}</Alerta>

  const { indicadores: i, por_mes: porMes, compras } = datos.datos

  return (
    <div className="productor">
      <EncabezadoPagina
        titulo={soloIndicadores ? 'Mis indicadores' : 'Mis ventas a FEDER'}
        descripcion="Lo que FEDER te compró y lo que ya te pagó."
      />
      <div className="indicadores">
        <Indicador etiqueta="Total vendido a FEDER" valor={formatearMoneda(i.total_vendido)} />
        <Indicador etiqueta="Cobrado" valor={formatearMoneda(i.cobrado)} />
        <Indicador etiqueta="Por cobrar" valor={formatearMoneda(i.por_cobrar)} detalle="compras recibidas sin pago" />
        <Indicador etiqueta="Compras" valor={formatearNumero(i.compras)} />
      </div>

      {soloIndicadores && (
        <TarjetaGrafico titulo="Ventas a FEDER por mes" vacio={porMes.length === 0}
          resumen={porMes.map((m) => `${nombreMes(m.mes)}: ${formatearMoneda(m.total)}`)}>
          <ResponsiveContainer>
            <BarChart accessibilityLayer={false} data={porMes.map((m) => ({ ...m, etiqueta: nombreMes(m.mes) }))}>
              <CartesianGrid stroke={c.borde} strokeDasharray="3 3" />
              <XAxis dataKey="etiqueta" tick={{ fill: c['texto-suave'], fontSize: 12 }} stroke={c.borde} />
              <YAxis tick={{ fill: c['texto-suave'], fontSize: 12 }} stroke={c.borde} />
              <Tooltip contentStyle={{ backgroundColor: c.superficie, color: c.texto }} itemStyle={{ color: c.texto }} formatter={(v) => [formatearMoneda(v), 'Vendido']} />
              <Bar dataKey="total" fill={c.primario} radius={[4, 4, 0, 0]} />
            </BarChart>
          </ResponsiveContainer>
        </TarjetaGrafico>
      )}

      {!soloIndicadores && (
        <>
        {errorDetalle && <Alerta tipo="error">{errorDetalle}</Alerta>}
        <TablaDatos etiqueta="Mis ventas a FEDER" filas={compras} columnas={[
          { clave: 'codigo', etiqueta: 'Compra' },
          { clave: 'fecha', etiqueta: 'Fecha', alRender: (cp) => formatearFecha(cp.fecha) },
          { clave: 'estado', etiqueta: 'Estado', alRender: (cp) => <Insignia tono={ESTADOS[cp.estado]?.[1]}>{ESTADOS[cp.estado]?.[0] ?? cp.estado}</Insignia> },
          { clave: 'lineas', etiqueta: 'Productos' },
          { clave: 'total', etiqueta: 'Total', alRender: (cp) => formatearMoneda(cp.total) },
          { clave: 'pago', etiqueta: 'Pago', alRender: (cp) => cp.pagada_el ? `Pagada el ${formatearFecha(cp.pagada_el)}` : 'Pendiente' },
          { clave: 'acciones', etiqueta: 'Acciones', alRender: (cp) => <Boton variante="fantasma" aria-label={`Ver compra ${cp.codigo}`} onClick={(ev) => verDetalle(cp.id, ev.currentTarget)}>Ver compra</Boton> },
        ]} />
        {detalle && <section className="productor__panel"><div className="productor__fila-acciones"><div><h2 ref={tituloDetalle} tabIndex={-1}>{detalle.codigo}</h2><p>{detalle.logistica?.modalidad === 'RECOJO_FEDER' ? 'FEDER recoge del productor' : 'El productor entrega en FEDER'}</p></div><Boton variante="fantasma" onClick={() => { setDetalle(null); botonOrigen.current?.focus() }}>Cerrar</Boton></div>
          <dl className="productor__datos"><dt>Estado</dt><dd>{ESTADOS[detalle.estado]?.[0] || detalle.estado}</dd><dt>Total</dt><dd>{formatearMoneda(detalle.total)}</dd><dt>Pago</dt><dd>{detalle.pagada_el ? formatearFecha(detalle.pagada_el) : 'Pendiente'}</dd><dt>Fecha acordada</dt><dd>{detalle.logistica?.fecha_acordada ? formatearFecha(detalle.logistica.fecha_acordada) : 'Sin fecha'}</dd><dt>Responsable del traslado</dt><dd>{detalle.logistica?.responsable || 'No especificado'}{detalle.logistica?.telefono ? ` · ${detalle.logistica.telefono}` : ''}</dd></dl>
          <div className="productor__tabla-contenedor" role="region" tabIndex={0} aria-label="Cantidades de la compra"><table className="productor__tabla"><thead><tr><th scope="col">Producto</th><th scope="col">Lote</th><th scope="col">Comprado</th><th scope="col">Recibido</th><th scope="col">Rechazado</th><th scope="col">Cancelado</th></tr></thead><tbody>{detalle.lineas.map((l) => <tr key={l.id}><td>{l.producto}</td><td>{l.lote}</td><td>{cantidadConUnidad(l.comprada, l.unidad_medida)}</td><td>{cantidadConUnidad(l.recibida, l.unidad_medida)}</td><td>{cantidadConUnidad(l.rechazada, l.unidad_medida)}</td><td>{cantidadConUnidad(l.cancelada, l.unidad_medida)}</td></tr>)}</tbody></table></div>
        </section>}
        </>
      )}
    </div>
  )
}
