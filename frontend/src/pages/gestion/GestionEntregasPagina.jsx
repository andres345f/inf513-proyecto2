import { useCallback, useState } from 'react'

import { Link, useSearchParams } from 'react-router-dom'

import { gestionApi } from '../../api/gestionApi'

import Alerta from '../../components/ui/Alerta'

import Boton from '../../components/ui/Boton'
import DialogoConfirmacion from '../../components/ui/DialogoConfirmacion'

import Insignia from '../../components/ui/Insignia'

import PaginaListado from '../../components/plantillas/PaginaListado'

import TablaDatos from '../../components/plantillas/TablaDatos'

import { useCargaApi } from '../../hooks/useCargaApi'

import { useMensajeTemporal } from '../../hooks/useMensajeTemporal'

import { avisoConflictoEnLista, cuerpoAvance, esConflicto } from '../../utils/avancePedido'

import { ESTADOS_ENTREGA, ESTADOS_PEDIDO, MODALIDADES, tonoEstado } from '../../utils/etiquetas'

import { formatearFecha, formatearMoneda } from '../../utils/formato'

import '../../components/plantillas/gestion.css'

/** Texto del botón según a qué estado avanza el pedido y cómo se entrega. */
function textoAvance(avanzarA, modalidad) {
  if (avanzarA === 'EN_PREPARACION') return 'Preparar'
  if (avanzarA === 'DESPACHADO') return modalidad === 'DELIVERY' ? 'Enviar' : 'Lista para recoger'
  return 'Marcar entregada'
}

const TONO_ENTREGA = { ENTREGADA: 'exito', EN_CAMINO: 'info', LISTA_PARA_RECOJO: 'info', PENDIENTE: 'aviso' }

/** Seguimiento de entregas (recojo y delivery) con avance rápido del pedido. */
export default function GestionEntregasPagina() {
  const [parametros, setParametros] = useSearchParams()
  const clave = parametros.toString()
  const lista = useCargaApi(() => gestionApi.entregas(Object.fromEntries(parametros)), [clave])
  const [ocupado, setOcupado] = useState(null)
  const [mensaje, setMensaje] = useState(null)
  const [decision, setDecision] = useState(null)
  useMensajeTemporal(mensaje, setMensaje)
  const [errorFila, setErrorFila] = useState(null)
  const [responsables, setResponsables] = useState({})
  const [confirmacionesEfectivo, setConfirmacionesEfectivo] = useState({})

  const filtrar = useCallback((cambios) => {
    setParametros((actuales) => {
      const nuevos = new URLSearchParams(actuales)
      Object.entries(cambios).forEach(([c, v]) => (v ? nuevos.set(c, v) : nuevos.delete(c)))
      if (!('pagina' in cambios)) nuevos.delete('pagina')
      return nuevos
    })
  }, [setParametros])

  const cambiar = (e) => filtrar({ [e.target.name]: e.target.value })
  const valor = (c) => parametros.get(c) || ''

  const avanzar = (entrega) => {
    const pedidoId = entrega.pedido.id
    const responsable = responsables[pedidoId]?.nombre?.trim() || ''
    const efectivoConfirmado = Boolean(confirmacionesEfectivo[pedidoId])
    if (entrega.avanzar_a === 'DESPACHADO' && entrega.modalidad === 'DELIVERY' && responsable.length < 3) {
      setErrorFila({ pedidoId, campo: 'responsable', texto: 'Indica quién llevará el pedido antes de enviarlo.' })
      document.getElementById(`responsable-${pedidoId}`)?.focus()
      return
    }
    if (entrega.requiere_confirmar_efectivo && !efectivoConfirmado) {
      setErrorFila({ pedidoId, campo: 'efectivo', texto: 'Confirma que FEDER recibió el pago en efectivo.' })
      document.getElementById(`efectivo-${pedidoId}`)?.focus()
      return
    }
    setErrorFila(null)
    const datos = cuerpoAvance(entrega.avanzar_a, {
      responsable, telefono: responsables[pedidoId]?.telefono || '', efectivoConfirmado,
    })
    setDecision({
      titulo: textoAvance(entrega.avanzar_a, entrega.modalidad), accion: 'Confirmar avance',
      mensaje: `El pedido ${entrega.pedido.codigo} pasará a ${(ESTADOS_PEDIDO[entrega.avanzar_a] ?? entrega.avanzar_a).toLowerCase()}.${entrega.requiere_confirmar_efectivo ? ' Se registrará el efectivo recibido.' : ''}`,
      alConfirmar: () => registrarAvance(entrega, datos),
    })
  }

  const registrarAvance = async (entrega, datos) => {
    const pedidoId = entrega.pedido.id
    setOcupado(pedidoId)
    setMensaje(null)
    try {
      setMensaje({ tipo: 'exito', texto: (await gestionApi.avanzarEntrega(pedidoId, datos)).mensaje })
      lista.recargar()
    } catch (falla) {
      if (esConflicto(falla)) {
        // La fila estaba desactualizada: no se aplicó nada y la lista se vuelve a cargar con el estado real.
        setMensaje({ tipo: 'aviso', texto: avisoConflictoEnLista(entrega.pedido.codigo, falla) })
        lista.recargar()
        return
      }
      setMensaje({ tipo: 'error', texto: falla.mensaje })
    } finally {
      setOcupado(null)
    }
  }

  const filtros = (
    <>
      <label>Buscar<input type="search" name="q" defaultValue={valor('q')} maxLength={60} placeholder="Pedido, cliente o dirección"
        onKeyDown={(e) => e.key === 'Enter' && filtrar({ q: e.currentTarget.value })} onBlur={(e) => filtrar({ q: e.target.value })} /></label>
      <label>Estado<select name="estado" value={valor('estado')} onChange={cambiar}>
        <option value="">Todos</option>
        {Object.entries(ESTADOS_ENTREGA).map(([c, t]) => <option key={c} value={c}>{t}</option>)}
      </select></label>
      <label>Entrega<select name="modalidad" value={valor('modalidad')} onChange={cambiar}>
        <option value="">Todas</option>
        {Object.entries(MODALIDADES).map(([c, t]) => <option key={c} value={c}>{t}</option>)}
      </select></label>
      <label>Desde<input type="date" name="desde" value={valor('desde')} onChange={cambiar} /></label>
      <label>Hasta<input type="date" name="hasta" value={valor('hasta')} onChange={cambiar} /></label>
    </>
  )

  const columnas = [
    {
      clave: 'pedido', etiqueta: 'Pedido',
      alRender: (e) => <><Link to={`/gestion/pedidos/${e.pedido.id}`}>{e.pedido.codigo}</Link><br /><small>{e.cliente}</small></>,
    },
    { clave: 'fecha', etiqueta: 'Fecha', alRender: (e) => formatearFecha(e.pedido.fecha) },
    { clave: 'modalidad', etiqueta: 'Entrega', alRender: (e) => MODALIDADES[e.modalidad] },
    { clave: 'receptor', etiqueta: 'Recibe', alRender: (e) => <>{e.receptor}<br /><small><a href={`tel:${e.telefono}`}>{e.telefono}</a></small></> },
    {
      clave: 'direccion', etiqueta: 'Dirección',
      alRender: (e) => <>{e.direccion ?? 'Recoge en almacén'}{e.referencia && <><br /><small>{e.referencia}</small></>}{e.observacion && <><br /><small>Descripción: {e.observacion}</small></>}</>,
    },
    { clave: 'costo_envio', etiqueta: 'Envío', alRender: (e) => formatearMoneda(e.costo_envio) },
    { clave: 'estado_entrega', etiqueta: 'Estado entrega', alRender: (e) => <Insignia tono={TONO_ENTREGA[e.estado] ?? 'error'}>{ESTADOS_ENTREGA[e.estado]}</Insignia> },
    { clave: 'estado_pedido', etiqueta: 'Estado pedido', alRender: (e) => <Insignia tono={tonoEstado(e.pedido.estado)}>{ESTADOS_PEDIDO[e.pedido.estado]}</Insignia> },
    {
      clave: 'accion', etiqueta: 'Acción',
      alRender: (e) => (
        <>
          {e.responsable_entrega && <><small>Lleva: {e.responsable_entrega}{e.telefono_responsable ? ` · ${e.telefono_responsable}` : ''}</small><br /></>}
          {e.avanzar_a === 'DESPACHADO' && e.modalidad === 'DELIVERY' && (
            <div className="gestion__accion-entrega">
              <label htmlFor={`responsable-${e.pedido.id}`}>Responsable de la entrega</label>
              <input id={`responsable-${e.pedido.id}`} aria-invalid={errorFila?.pedidoId === e.pedido.id && errorFila.campo === 'responsable'}
                aria-describedby={errorFila?.pedidoId === e.pedido.id && errorFila.campo === 'responsable' ? `error-entrega-${e.pedido.id}` : undefined} placeholder="Quién llevará el pedido" value={responsables[e.pedido.id]?.nombre || ''} onChange={(evento) => setResponsables((v) => ({ ...v, [e.pedido.id]: { ...v[e.pedido.id], nombre: evento.target.value } }))} />
              <label htmlFor={`telefono-${e.pedido.id}`}>Teléfono del responsable (opcional)</label>
              <input id={`telefono-${e.pedido.id}`} type="tel" placeholder="Teléfono" value={responsables[e.pedido.id]?.telefono || ''} onChange={(evento) => setResponsables((v) => ({ ...v, [e.pedido.id]: { ...v[e.pedido.id], telefono: evento.target.value } }))} />
            </div>
          )}
          {e.requiere_confirmar_efectivo && (
            <label className="gestion__confirmacion">
              <input type="checkbox" id={`efectivo-${e.pedido.id}`} checked={Boolean(confirmacionesEfectivo[e.pedido.id])}
                aria-invalid={errorFila?.pedidoId === e.pedido.id && errorFila.campo === 'efectivo'}
                aria-describedby={errorFila?.pedidoId === e.pedido.id && errorFila.campo === 'efectivo' ? `error-entrega-${e.pedido.id}` : undefined}
                onChange={(evento) => setConfirmacionesEfectivo((v) => ({ ...v, [e.pedido.id]: evento.target.checked }))} />
              Confirmo que FEDER recibió {formatearMoneda(e.efectivo_a_recibir)} en efectivo
            </label>
          )}
          {errorFila?.pedidoId === e.pedido.id && <p className="campo__mensaje-error" id={`error-entrega-${e.pedido.id}`} role="alert">{errorFila.texto}</p>}
          {e.avanzar_a ? (
            <Boton cargando={ocupado === e.pedido.id} onClick={() => avanzar(e)}>{textoAvance(e.avanzar_a, e.modalidad)}</Boton>
          ) : '—'}
        </>
      ),
    },
  ]

  return (
    <PaginaListado
      titulo="Entregas"
      descripcion="Seguimiento de recojo y delivery, con avance rápido del estado del pedido."
      etiquetaFiltros="Filtrar entregas"
      alLimpiarFiltros={() => setParametros({})}
      filtros={filtros}
      cargando={lista.cargando && !lista.datos}
      error={lista.error}
      resumen={lista.datos ? `${lista.datos.paginacion.total ?? 0} entregas` : null}
      vacio={lista.datos?.entregas.length === 0 ? { titulo: 'No hay entregas con esos filtros', mensaje: 'Prueba ampliando el rango de fechas o cambiando el estado.' } : null}
      paginacion={lista.datos?.entregas.length ? {
        pagina: lista.datos.paginacion.pagina,
        paginas: lista.datos.paginacion.paginas,
        alCambiar: (pagina) => filtrar({ pagina: String(pagina) }),
      } : null}
    >
      {decision && <DialogoConfirmacion {...decision} alCerrar={() => setDecision(null)} />}
      {mensaje && <Alerta tipo={mensaje.tipo}>{mensaje.texto}</Alerta>}
      <TablaDatos columnas={columnas} filas={lista.datos?.entregas ?? []} />
    </PaginaListado>
  )
}
