import { NavLink } from 'react-router-dom'

import { HandCoins, Receipt } from 'lucide-react'

import { comercioApi } from '../../api/comercioApi'

import Alerta from '../ui/Alerta'

import Cargando from '../ui/Cargando'

import Insignia from '../ui/Insignia'

import EncabezadoPagina from '../plantillas/EncabezadoPagina'

import EstadoVacio from '../plantillas/EstadoVacio'

import TablaDatos from '../plantillas/TablaDatos'

import { useCargaApi } from '../../hooks/useCargaApi'

import { useSesion } from '../../hooks/useSesion'

import { ESTADOS_PAGO, ESTADOS_PLAN, METODOS_PAGO, etiquetaCuota, tonoEstado } from '../../utils/etiquetas'

import { formatearFecha, formatearFechaHora, formatearMoneda } from '../../utils/formato'

import '../../pages/pagos/PlanesPagoPagina.css'

import './PagosCliente.css'

/**
 * Vista compartida de la sección de pagos del cliente.
 *
 * /mis-pagos y /planes-pago son rutas independientes con sus propios permisos,
 * pero presentan el mismo armazón con pestañas: cada pestaña es un enlace real
 * (navegación y marcadores funcionan) y el panel activo trae solo sus datos.
 *
 * Secciones: 'historial' (pagos realizados) y 'cuotas' (planes activos).
 */

const COLUMNAS_HISTORIAL = (hayPedidos) => [
  {
    clave: 'pedido', etiqueta: 'Pedido',
    alRender: (pago) => hayPedidos
      ? <NavLink to={`/mis-pedidos/${pago.pedido.id}`} className="pagos-cliente__enlace">{pago.pedido.codigo}</NavLink>
      : pago.pedido.codigo,
  },
  { clave: 'fecha', etiqueta: 'Fecha', alRender: (pago) => formatearFechaHora(pago.fecha) },
  { clave: 'metodo', etiqueta: 'Método', alRender: (pago) => METODOS_PAGO[pago.metodo] || pago.metodo },
  { clave: 'referencia', etiqueta: 'Referencia', alRender: (pago) => pago.referencia || '—' },
  { clave: 'monto', etiqueta: 'Monto', alRender: (pago) => formatearMoneda(pago.monto) },
  { clave: 'estado', etiqueta: 'Estado', alRender: (pago) => <Insignia tono={tonoEstado(pago.estado)}>{ESTADOS_PAGO[pago.estado] || pago.estado}</Insignia> },
]

export default function PagosCliente({ seccion = 'historial' }) {
  // Cada pestaña solo aparece si el permiso del rol activo la autoriza:
  // sin permiso no se enlaza a una ruta que terminaría en SinPermisoPagina.
  const { tienePermiso } = useSesion() // RutaProtegida ya garantizó sesión
  const hayHistorial = tienePermiso('pagos.propios')
  const hayCuotas = tienePermiso('planes_pago.propios')

  const titulo = seccion === 'cuotas' ? 'Planes de pago' : 'Mis pagos'
  const descripcion = seccion === 'cuotas'
    ? 'Consulta el estado de tus cuotas y paga la siguiente cuando toque.'
    : 'Historial de pagos, cobros pendientes y cuotas de tus pedidos.'

  return (
    <section className="pagina-listado">
      <EncabezadoPagina titulo={titulo} descripcion={descripcion}>
        {(hayHistorial || hayCuotas) && (
          <nav className="pagos-cliente__pestanas" aria-label="Secciones de pagos">
            {hayHistorial && (
              <NavLink to="/mis-pagos" replace className="pagos-cliente__pestana"
                aria-current={seccion === 'historial' ? 'page' : undefined}>
                Historial de pagos
              </NavLink>
            )}
            {hayCuotas && (
              <NavLink to="/planes-pago" replace className="pagos-cliente__pestana"
                aria-current={seccion === 'cuotas' ? 'page' : undefined}>
                Cuotas
              </NavLink>
            )}
          </nav>
        )}
      </EncabezadoPagina>

      {seccion === 'cuotas'
        ? (hayCuotas ? <PanelCuotas /> : <Alerta tipo="aviso">No tienes permiso para consultar cuotas con este rol.</Alerta>)
        : (hayHistorial ? <PanelHistorial /> : <Alerta tipo="aviso">No tienes permiso para consultar pagos con este rol.</Alerta>)}
    </section>
  )
}

function PanelHistorial() {
  const pagos = useCargaApi(() => comercioApi.misPagos(), [])
  const { tienePermiso } = useSesion()
  const hayPedidos = tienePermiso('pedidos.propios') // el enlace a pedidos solo con su permiso

  if (pagos.cargando) return <Cargando texto="Cargando tus pagos…" />
  if (pagos.error) return <Alerta tipo="error">{pagos.error}</Alerta>

  if (!pagos.datos?.pagos.length) {
    return <EstadoVacio titulo="Todavía no realizaste ningún pago" icono={Receipt}
      mensaje="Cuando pagues un pedido, su comprobante quedará asentado en este historial." />
  }

  return <TablaDatos columnas={COLUMNAS_HISTORIAL(hayPedidos)} filas={pagos.datos.pagos} />
}

function PanelCuotas() {
  const planes = useCargaApi(() => comercioApi.planesPago(), [])
  const { tienePermiso } = useSesion() // el enlace a pedidos solo con su permiso
  const hayPedidos = tienePermiso('pedidos.propios')

  if (planes.cargando) return <Cargando texto="Cargando tus planes de pago…" />
  if (planes.error) return <Alerta tipo="error">{planes.error}</Alerta>

  return (
    <>
      {planes.datos?.tiene_cuota_vencida && (
        <Alerta tipo="error">Tienes una cuota vencida. Págala para volver a realizar compras.</Alerta>
      )}
      {planes.datos?.planes.some((p) => p.estado === 'ACTIVO') && (
        <Alerta tipo="info">Cada cuota paga solo los productos de su pedido. Si agregas productos al carrito, serán una compra nueva con su propio pago.</Alerta>
      )}
      {!planes.datos?.planes.length && (
        <EstadoVacio titulo="No tienes planes de pago" icono={HandCoins}
          mensaje="Cuando compres en cuotas, cada plan aparecerá aquí con sus vencimientos." />
      )}
      {planes.datos?.planes.length > 0 && (
        <div className="planes-pago__lista">
          {planes.datos.planes.map((plan) => (
            <article key={plan.id} className="planes-pago__tarjeta">
              <header>
                <div><span>Pedido</span>{hayPedidos
                  ? <NavLink to={`/mis-pedidos/${plan.pedido_id}`} className="pagos-cliente__enlace">{plan.pedido_codigo}</NavLink>
                  : plan.pedido_codigo}</div>
                <Insignia tono={tonoEstado(plan.estado)}>{ESTADOS_PLAN[plan.estado] || plan.estado}</Insignia>
              </header>
              <dl>
                <dt>Total</dt><dd>{formatearMoneda(plan.total)}</dd>
                <dt>Pagado</dt><dd>{formatearMoneda(plan.pagado)}</dd>
                {plan.descontado_por_devoluciones > 0 && (
                  <><dt>Descontado por devoluciones</dt><dd>{formatearMoneda(plan.descontado_por_devoluciones)}</dd></>
                )}
                <dt>Saldo</dt><dd>{formatearMoneda(plan.saldo)}</dd>
              </dl>
              <ol>
                {plan.cuotas.map((cuota) => (
                  <li key={cuota.numero} className={cuota.vencida ? 'planes-pago__vencida' : ''}>
                    <div><strong>Cuota {cuota.numero}</strong><span>Vence {formatearFecha(cuota.vence)}</span></div>
                    <div>
                      <strong>{formatearMoneda(cuota.monto)}</strong>
                      {cuota.monto_original != null && <span>Original: {formatearMoneda(cuota.monto_original)}</span>}
                      <Insignia tono={cuota.estado === 'ANULADA' ? 'info' : cuota.estado === 'PAGADA' ? 'exito' : cuota.vencida ? 'error' : 'aviso'}>
                        {etiquetaCuota(cuota)}
                      </Insignia>
                    </div>
                  </li>
                ))}
              </ol>
              {plan.estado === 'ACTIVO' && hayPedidos && (
                <NavLink className="planes-pago__pagar" to={`/mis-pedidos/${plan.pedido_id}`}>Ir a pagar la siguiente cuota</NavLink>
              )}
              {plan.estado === 'ACTIVO' && !hayPedidos && (
                <p className="planes-pago__nota">Para pagar la siguiente cuota necesitas acceso a tus pedidos con este rol.</p>
              )}
            </article>
          ))}
        </div>
      )}
    </>
  )
}
