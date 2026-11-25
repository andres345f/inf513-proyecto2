import { useState } from 'react'

import { Link } from 'react-router-dom'

import { BellOff } from 'lucide-react'

import { EVENTO_NOTIFICACIONES, notificacionesApi } from '../../api/notificacionesApi'

import Boton from '../../components/ui/Boton'

import Insignia from '../../components/ui/Insignia'

import PaginaListado from '../../components/plantillas/PaginaListado'

import TarjetaItem from '../../components/plantillas/TarjetaItem'

import { useCargaApi } from '../../hooks/useCargaApi'

import { useSesion } from '../../hooks/useSesion'

import { formatearFechaHora } from '../../utils/formato'

function avisarContador(noLeidas) {
  window.dispatchEvent(new CustomEvent(EVENTO_NOTIFICACIONES, { detail: noLeidas }))
}

export default function NotificacionesPagina() {
  const { tienePermiso } = useSesion()
  const [soloNoLeidas, setSoloNoLeidas] = useState(false)
  const [pagina, setPagina] = useState(1)
  const [error, setError] = useState(null)
  const lista = useCargaApi(() => notificacionesApi.listar({ no_leidas: soloNoLeidas ? 1 : 0, pagina }), [soloNoLeidas, pagina])
  const rutaPedido = tienePermiso('pedidos.gestionar') ? '/gestion/pedidos' : '/mis-pedidos'

  const leer = async (notificacion) => {
    if (notificacion.leida) return
    try {
      const r = await notificacionesApi.leer(notificacion.id)
      avisarContador(r.no_leidas)
      lista.reemplazar({
        ...lista.datos,
        no_leidas: r.no_leidas,
        notificaciones: lista.datos.notificaciones.map((n) => (n.id === notificacion.id ? { ...n, leida: true } : n)),
      })
    } catch (falla) {
      setError(falla.mensaje)
    }
  }

  const leerTodas = async () => {
    try {
      avisarContador((await notificacionesApi.leerTodas()).no_leidas)
      lista.recargar()
    } catch (falla) {
      setError(falla.mensaje)
    }
  }

  return (
    <PaginaListado
      titulo="Notificaciones"
      descripcion="Avisos sobre tus pedidos, pagos, devoluciones y propuestas."
      accionPrincipal={
        <>
          <label className="pagina-encabezado__toggle">
            <input type="checkbox" checked={soloNoLeidas} onChange={(e) => { setSoloNoLeidas(e.target.checked); setPagina(1) }} />
            Solo sin leer
          </label>
          <Boton variante="secundario" onClick={leerTodas} disabled={!lista.datos?.no_leidas}>Marcar todas como leídas</Boton>
        </>
      }
      cargando={lista.cargando && !lista.datos}
      error={error || lista.error}
      vacio={lista.datos?.notificaciones.length === 0 ? {
        titulo: soloNoLeidas ? 'No tienes notificaciones sin leer' : 'Todavía no tienes notificaciones',
        mensaje: soloNoLeidas ? 'Cuando llegue algo nuevo aparecerá aquí primero.' : 'Los avisos de tus pedidos y pagos llegarán a este buzón.',
        icono: BellOff,
      } : null}
      paginacion={lista.datos?.notificaciones.length ? {
        pagina: lista.datos.paginacion.pagina,
        paginas: lista.datos.paginacion.paginas,
        alCambiar: setPagina,
      } : null}
    >
      <div className="pagina-listado__lista">
        {lista.datos?.notificaciones.map((n, indice) => (
          <TarjetaItem
            key={n.id}
            titulo={n.titulo}
            destacada={!n.leida}
            insignia={!n.leida ? <Insignia tono="info">Nueva</Insignia> : null}
            className="entrada"
            style={{ '--animacion-retraso': `${Math.min(indice * 60, 360)}ms` }}
            acciones={
              <>
                {n.pedido_id && <Link to={`${rutaPedido}/${n.pedido_id}`} onClick={() => leer(n)}>Ver pedido</Link>}
                {!n.leida && <Boton variante="fantasma" onClick={() => leer(n)}>Marcar como leída</Boton>}
              </>
            }
          >
            <p>{n.mensaje}</p>
            <span className="tarjeta-item__subtitulo">{formatearFechaHora(n.fecha)}</span>
          </TarjetaItem>
        ))}
      </div>
    </PaginaListado>
  )
}
