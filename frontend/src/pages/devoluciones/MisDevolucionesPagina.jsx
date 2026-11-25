import { useState } from 'react'

import { devolucionesApi } from '../../api/devolucionesApi'

import AsistenteDevolucion from '../../components/cliente/AsistenteDevolucion'

import Alerta from '../../components/ui/Alerta'

import Boton from '../../components/ui/Boton'

import Insignia from '../../components/ui/Insignia'

import PaginaListado from '../../components/plantillas/PaginaListado'

import TarjetaItem from '../../components/plantillas/TarjetaItem'

import { useCargaApi } from '../../hooks/useCargaApi'

import { useMensajeTemporal } from '../../hooks/useMensajeTemporal'

import { ESTADOS_DEVOLUCION, RESOLUCIONES, cantidadConUnidad, tonoEstado } from '../../utils/etiquetas'

import { formatearFecha, formatearMoneda } from '../../utils/formato'

/** Qué pasó o qué falta, en palabras del cliente. */
function situacion(d) {
  if (d.estado === 'SOLICITADA') return 'Lleva el producto a FEDER para que lo revisemos.'
  if (d.estado === 'RECHAZADA') return 'No pudimos aceptar esta devolución. El motivo está en tus notificaciones.'
  const compensacion = d.resolucion === 'REEMBOLSO' ? `reintegro de ${formatearMoneda(d.importe)}` : `reposición de ${cantidadConUnidad(d.cantidad_aprobada, d.unidad)}`
  return d.estado === 'COMPLETADA' ? `Completada: ${compensacion}.` : `Aprobada: ${compensacion} en proceso.`
}

/** Devoluciones del cliente: el alta va en un asistente en dos pasos (Fase B). */
export default function MisDevolucionesPagina() {
  const resumen = useCargaApi(() => devolucionesApi.mias(), [])
  const [asistenteAbierto, setAsistenteAbierto] = useState(false)
  const [mensaje, setMensaje] = useState(null)
  useMensajeTemporal(mensaje, setMensaje)

  const devoluciones = resumen.datos?.devoluciones ?? []
  const hayDevolvibles = (resumen.datos?.devolvibles ?? []).length > 0

  return (
    <PaginaListado
        className="pagina-listado--apilada"
        titulo="Mis devoluciones"
        descripcion="Solicita la devolución de un producto de un pedido entregado y sigue su estado."
        accionPrincipal={hayDevolvibles && <Boton onClick={() => setAsistenteAbierto(true)}>Solicitar una devolución</Boton>}
        cargando={resumen.cargando} actualizando={resumen.actualizando} error={resumen.error} alReintentar={resumen.recargar}>
      {mensaje && <Alerta tipo={mensaje.tipo}>{mensaje.texto}</Alerta>}

      {!hayDevolvibles && (
        <Alerta tipo="info">Cuando recibas un pedido podrás solicitar aquí la devolución de algún producto.</Alerta>
      )}

      <h2>Mis solicitudes</h2>

      {devoluciones.length === 0 ? (
        <p className="pagina-listado__resumen">Todavía no solicitaste devoluciones.</p>
      ) : (
        <div className="pagina-listado__lista">
          {devoluciones.map((d) => (
            <TarjetaItem
              key={d.id}
              titulo={d.codigo}
              subtitulo={`${d.producto} · ${cantidadConUnidad(d.cantidad, d.unidad)} · Pedido ${d.pedido.codigo} · ${formatearFecha(d.fecha_solicitud)}`}
              insignia={<Insignia tono={tonoEstado(d.estado)}>{ESTADOS_DEVOLUCION[d.estado]}</Insignia>}
            >
              <p>«{d.motivo}»</p>
              {d.resolucion && <p>{RESOLUCIONES[d.resolucion]}</p>}
              <p>{situacion(d)}</p>
            </TarjetaItem>
          ))}
        </div>
      )}

      {asistenteAbierto && (
        <AsistenteDevolucion
          alCerrar={() => setAsistenteAbierto(false)}
          alSolicitada={(texto) => { resumen.recargar(); setMensaje({ tipo: 'exito', texto }) }}
        />
      )}
    </PaginaListado>
  )
}
