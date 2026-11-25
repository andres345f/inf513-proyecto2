import { Link } from 'react-router-dom'
import { comercioApi } from '../../api/comercioApi'
import { devolucionesApi } from '../../api/devolucionesApi'
import { productoresApi } from '../../api/productoresApi'
import EncabezadoPagina from '../../components/plantillas/EncabezadoPagina'
import Alerta from '../../components/ui/Alerta'
import Insignia from '../../components/ui/Insignia'
import { useCargaApi } from '../../hooks/useCargaApi'
import { useSesion } from '../../hooks/useSesion'
import { ESTADOS_PEDIDO, ESTADOS_DEVOLUCION, tonoEstado } from '../../utils/etiquetas'
import { formatearFecha, formatearFechaHora, formatearMoneda } from '../../utils/formato'
import './MiCuenta.css'

function useResumen(permitido, consulta) {
  return useCargaApi(() => permitido ? consulta() : Promise.resolve(null), [permitido])
}

export default function MiCuentaPagina() {
  const { sesion, tienePermiso } = useSesion()
  const hayPedidos = tienePermiso('pedidos.propios')
  const hayPlanes = tienePermiso('planes_pago.propios')
  const hayPagos = tienePermiso('pagos.propios')
  const hayCarrito = tienePermiso('carrito.usar')
  const hayDevoluciones = tienePermiso('devoluciones.propias')
  const puedeSolicitar = tienePermiso('perfil.ver') && sesion.roles.some((r) => r.codigo === 'CLIENTE')
    && !sesion.roles.some((r) => r.codigo === 'PRODUCTOR')
  const pedidos = useResumen(hayPedidos, () => comercioApi.misPedidos({ pagina: 1 }))
  const planes = useResumen(hayPlanes, comercioApi.planesPago)
  const pagos = useResumen(hayPagos, comercioApi.misPagos)
  const carrito = useResumen(hayCarrito, comercioApi.carrito)
  const devoluciones = useResumen(hayDevoluciones, devolucionesApi.mias)
  const solicitud = useResumen(puedeSolicitar, productoresApi.estadoSolicitud)
  const consultas = [
    ['pedidos', pedidos, hayPedidos], ['cuotas', planes, hayPlanes], ['pagos', pagos, hayPagos],
    ['carrito', carrito, hayCarrito], ['devoluciones', devoluciones, hayDevoluciones],
    ['solicitud de productor', solicitud, puedeSolicitar],
  ].filter(([, , permitido]) => permitido)

  const cargando = consultas.some(([, c]) => c.cargando)
  const errores = consultas.filter(([, c]) => c.error)
  const abiertos = hayPedidos && !pedidos.error ? (pedidos.datos?.pedidos ?? []).filter((p) => !['ENTREGADO','CANCELADO'].includes(p.estado)).slice(0,3) : []
  const cuotas = hayPlanes && !planes.error ? (planes.datos?.planes ?? []).filter((p) => p.estado === 'ACTIVO')
    .flatMap((p) => p.cuotas.filter((c) => !['PAGADA','ANULADA'].includes(c.estado)).map((c) => ({ ...c, pedido: p.pedido_codigo })))
    .sort((a,b) => String(a.vence).localeCompare(String(b.vence))) : []
  const pendientes = hayPagos && !pagos.error ? (pagos.datos?.pagos ?? []).filter((p) => p.estado === 'PENDIENTE') : []
  const dev = hayDevoluciones && !devoluciones.error && devoluciones.datos?.devoluciones.find((d) => ['SOLICITADA','APROBADA'].includes(d.estado))
  const hayCompra = hayCarrito && !carrito.error && carrito.datos?.items?.length > 0
  const hayHistorial = Boolean(pedidos.datos?.pedidos?.length || planes.datos?.planes?.length || pagos.datos?.pagos?.length || devoluciones.datos?.devoluciones?.length)
  const cuota = cuotas[0]
  const activa = Boolean(abiertos.length || cuota || pendientes.length || dev || hayCompra)

  return <section className="mi-cuenta">
    <EncabezadoPagina titulo="Mi cuenta" />
    {cargando && <p role="status">Cargando tu actividad…</p>}
    {errores.map(([nombre, c]) => <Alerta key={nombre} tipo="error">No se pudo cargar {nombre}. <button type="button" onClick={c.recargar}>Reintentar</button></Alerta>)}
    {(cuota || pendientes.length > 0 || dev || hayCompra) && <section className="mi-cuenta__seccion">
      <h2>Para revisar</h2>
      <ul className="mi-cuenta__filas">
        {cuota && <li><div><strong>{cuota.vencida ? 'Cuota vencida' : 'Próxima cuota'} · {formatearMoneda(cuota.monto)}</strong>
          <span>{cuota.pedido} · {formatearFecha(cuota.vence)}</span></div><Link to="/planes-pago">Ver cuota</Link></li>}
        {pendientes.length > 0 && <li><div><strong>{pendientes.length === 1 ? 'Pago pendiente' : pendientes.length + ' pagos pendientes'}</strong>
          <span>{pendientes[0].pedido.codigo} · {formatearMoneda(pendientes[0].monto)}</span></div><Link to="/mis-pagos">Ver pagos</Link></li>}
        {dev && <li><div><strong>Devolución {dev.codigo}</strong><span>{dev.producto} · {ESTADOS_DEVOLUCION[dev.estado]}</span></div>
          <Link to="/mis-devoluciones">Ver devolución</Link></li>}
        {hayCompra && <li><div><strong>Carrito en curso</strong><span>Subtotal {formatearMoneda(carrito.datos.subtotal)}</span></div>
          <Link to="/carrito">Continuar compra</Link></li>}
      </ul>
    </section>}
    {abiertos.length > 0 && <section className="mi-cuenta__seccion">
      <div className="mi-cuenta__cabecera"><h2>Pedidos en curso</h2><Link to="/mis-pedidos">Ver todos mis pedidos</Link></div>
      <ul className="mi-cuenta__filas">{abiertos.map((p) => <li key={p.id}><div><Link to={'/mis-pedidos/' + p.id}><strong>{p.codigo}</strong></Link>
        <span>{formatearFechaHora(p.fecha)} · {formatearMoneda(p.total)}</span></div>
        <Insignia tono={tonoEstado(p.estado)}>{ESTADOS_PEDIDO[p.estado] ?? p.estado}</Insignia></li>)}</ul>
    </section>}
    {!cargando && !errores.length && !activa && <section className="mi-cuenta__seccion">
      <h2>{hayHistorial ? 'Sin pendientes en esta vista' : 'Todavía no hay actividad de compra'}</h2>
      {hayHistorial && hayPedidos ? <Link to="/mis-pedidos">Ver todos mis pedidos</Link> : tienePermiso('catalogo.ver') && <Link to="/catalogo">Explorar catálogo</Link>}
    </section>}
    {activa && abiertos.length === 0 && hayPedidos && pedidos.datos && !pedidos.error &&
      <p>Sin pedidos en esta vista. <Link to="/mis-pedidos">Ver todos mis pedidos</Link></p>}
    {puedeSolicitar && solicitud.datos && !solicitud.error && <p className="mi-cuenta__solicitud">
      {solicitud.datos.solicitud && <span>Solicitud de productor · {solicitud.datos.solicitud.estado.toLowerCase()} </span>}
      <Link to="/mi-solicitud">{solicitud.datos.solicitud ? 'Ver seguimiento de mi solicitud' : 'Quiero ser productor'}</Link></p>}
  </section>
}
