import { useState } from 'react'
import { Link, useLocation, useParams } from 'react-router-dom'
import { comercioApi } from '../../api/comercioApi'
import AsistenteDevolucion from '../../components/cliente/AsistenteDevolucion'
import DetallePedido from '../../components/comercio/DetallePedido'
import PanelPago from '../../components/comercio/PanelPago'
import Alerta from '../../components/ui/Alerta'
import Boton from '../../components/ui/Boton'
import Cargando from '../../components/ui/Cargando'
import Insignia from '../../components/ui/Insignia'
import MensajeCampo from '../../components/ui/MensajeCampo'
import Tarjeta from '../../components/ui/Tarjeta'
import { useCargaApi } from '../../hooks/useCargaApi'
import { useSesion } from '../../hooks/useSesion'
import { ESTADOS_PEDIDO, ESTADOS_PLAN, TIPOS_PEDIDO, etiquetaCuota, tonoEstado } from '../../utils/etiquetas'
import { formatearFecha, formatearFechaHora, formatearMoneda } from '../../utils/formato'
import { atributosError } from '../../utils/validaciones'
import './PedidoDetallePagina.css'

export default function PedidoDetallePagina() {
  const { id } = useParams()
  const ubicacion = useLocation()
  const { tienePermiso } = useSesion() // oculta la devolución si el rol no tiene el permiso (el API lo refuerza)
  const pedido = useCargaApi(() => comercioApi.pedido(id), [id])
  const [cancelando, setCancelando] = useState(false)
  const [devolucionAbierta, setDevolucionAbierta] = useState(false)
  const [motivo, setMotivo] = useState('')
  const [errorMotivo, setErrorMotivo] = useState(null)
  const [mensaje, setMensaje] = useState(null)

  const actualizar = pedido.reemplazar

  const cancelar = async (evento) => {
    evento.preventDefault()
    if (motivo.trim().length < 5) {
      setErrorMotivo('Explica brevemente (al menos 5 caracteres) por qué deseas cancelar el pedido.')
      document.getElementById('motivo-cancelacion')?.focus()
      return
    }
    setErrorMotivo(null)
    setCancelando(true)
    setMensaje(null)
    try {
      pedido.reemplazar(await comercioApi.cancelarPedido(id, motivo.trim()))
      setMensaje({ tipo: 'exito', texto: 'Cancelamos tu pedido y liberamos los productos reservados.' })
    } catch (falla) {
      setMensaje({ tipo: 'error', texto: falla.mensaje })
      if (falla.errores?.motivo) setErrorMotivo(falla.errores.motivo[0])
    } finally {
      setCancelando(false)
    }
  }

  if (pedido.cargando) return <Cargando texto="Cargando el pedido…" />
  if (pedido.error || !pedido.datos) return <Alerta tipo="error">{pedido.error || 'No pudimos encontrar ese pedido.'}</Alerta>
  const p = pedido.datos
  const puedeDevolver = p.estado === 'ENTREGADO' && tienePermiso('devoluciones.propias')

  return (
    <div className="pedido-detalle">
      <Link to={{ pathname: '/mis-pedidos', search: ubicacion.state?.consultaPedidos ?? '' }} className="pedido-detalle__volver">← Volver a mis pedidos</Link>
      <header className="pedido-detalle__encabezado">
        <div><p className="pedido-detalle__codigo">Pedido {p.codigo}</p><h1>Detalle de tu pedido</h1><p>Creado el {formatearFechaHora(p.fecha)}</p></div>
        <Insignia tono={tonoEstado(p.estado)}>{ESTADOS_PEDIDO[p.estado] || p.estado}</Insignia>
      </header>
      {ubicacion.state?.recienCreado && p.estado === 'PENDIENTE' && <Alerta tipo="exito">Tu pedido fue creado. Elige cómo deseas pagarlo.</Alerta>}
      {p.plazo_pago_vencido && (
        <Alerta tipo="aviso">Venció el plazo de 48 horas para pagar. {p.puede_cancelar
          ? 'Puedes cancelar el pedido y volver a comprar.'
          : 'FEDER cancelará el pedido después de revisar los pagos pendientes.'}</Alerta>
      )}
      {mensaje && <Alerta tipo={mensaje.tipo}>{mensaje.texto}</Alerta>}

      <div className="pedido-detalle__rejilla">
        <div className="pedido-detalle__principal">
          <DetallePedido pedido={p} enlazarProductos enlazarFactura />
        </div>

        <aside className="pedido-detalle__lateral">
          <Tarjeta titulo="Resumen"><dl className="pedido-detalle__datos"><dt>Tipo</dt><dd>{TIPOS_PEDIDO[p.tipo] || p.tipo}</dd><dt>Estado</dt><dd>{ESTADOS_PEDIDO[p.estado] || p.estado}</dd>{p.observacion && <><dt>Observación</dt><dd>{p.observacion}</dd></>}</dl></Tarjeta>
          {p.plan && (
            <Tarjeta titulo={`Plan de ${p.plan.num_cuotas} cuotas`}>
              <p>{ESTADOS_PLAN[p.plan.estado]} · {formatearMoneda(p.plan.pagado)} pagados de {formatearMoneda(p.plan.total)}</p>
              {p.plan.descontado_por_devoluciones > 0 && <p>Descontado por devoluciones: {formatearMoneda(p.plan.descontado_por_devoluciones)}</p>}
              <ul className="pedido-detalle__cuotas">
                {p.plan.cuotas.map((cuota) => (
                  <li key={cuota.numero} className={cuota.vencida ? 'pedido-detalle__cuota--vencida' : ''}>
                    <span>Cuota {cuota.numero} · vence {formatearFecha(cuota.vence)}</span>
                    <strong>{formatearMoneda(cuota.monto)} · {etiquetaCuota(cuota)}</strong>
                    {cuota.monto_original != null && <span>Original: {formatearMoneda(cuota.monto_original)}</span>}
                  </li>
                ))}
              </ul>
            </Tarjeta>
          )}
          {p.puede_pagar && <PanelPago key={p.id} pedido={p} alActualizar={actualizar} />}
          {puedeDevolver && <Tarjeta titulo="Devolución"><p>Si algún producto llegó fallado o incompleto, puedes solicitar la devolución.</p><Boton variante="secundario" bloque onClick={() => setDevolucionAbierta(true)}>Solicitar una devolución</Boton></Tarjeta>}
          {p.puede_cancelar && <Tarjeta titulo="Cancelar pedido"><form className="pedido-detalle__cancelar" onSubmit={cancelar} noValidate>
            <label htmlFor="motivo-cancelacion">Motivo</label>
            <textarea id="motivo-cancelacion" aria-required="true" value={motivo} maxLength={200} rows={3}
              onChange={(e) => { setMotivo(e.target.value); setErrorMotivo(null) }}
              {...atributosError({ motivo: errorMotivo }, 'motivo', 'motivo-cancelacion-error')} />
            <MensajeCampo id="motivo-cancelacion-error" error={errorMotivo} />
            <Boton tipo="submit" variante="peligro" bloque cargando={cancelando}>Cancelar pedido</Boton>
          </form></Tarjeta>}
        </aside>
      </div>

      {puedeDevolver && devolucionAbierta && (
        <AsistenteDevolucion
          pedidoFiltro={p.codigo}
          alCerrar={() => setDevolucionAbierta(false)}
          alSolicitada={(texto) => setMensaje({ tipo: 'exito', texto })}
        />
      )}
    </div>
  )
}
