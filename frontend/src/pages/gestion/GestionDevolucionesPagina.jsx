import { useCallback, useState } from 'react'

import { Link, useSearchParams } from 'react-router-dom'

import { devolucionesApi } from '../../api/devolucionesApi'

import ModalFlotante from '../../components/cliente/ModalFlotante'

import Alerta from '../../components/ui/Alerta'

import Boton from '../../components/ui/Boton'

import Insignia from '../../components/ui/Insignia'

import PaginaListado from '../../components/plantillas/PaginaListado'

import TablaDatos from '../../components/plantillas/TablaDatos'

import { useCargaApi } from '../../hooks/useCargaApi'

import { useMensajeTemporal } from '../../hooks/useMensajeTemporal'

import { ESTADOS_DEVOLUCION, RESOLUCIONES, cantidadConUnidad, tonoEstado } from '../../utils/etiquetas'

import { formatearFecha, formatearMoneda } from '../../utils/formato'

import '../../components/plantillas/gestion.css'

/**
 * Devoluciones para el Personal de FEDER: al recibir el producto se registra cuánto llegó y cuánto
 * sirve (eso vuelve al stock) y se aprueba con reposición o reintegro; luego se completa.
 * Aprobar, rechazar y completar se deciden dentro del modal «Revisar», nunca en la fila.
 */
export default function GestionDevolucionesPagina() {
  const [parametros, setParametros] = useSearchParams()
  const clave = parametros.toString()
  const lista = useCargaApi(() => devolucionesApi.listar(Object.fromEntries(parametros)), [clave])
  const [revisada, setRevisada] = useState(null) // devolución abierta en el modal
  const [modo, setModo] = useState(null) // null | 'aprobar' | 'rechazar'
  const [decision, setDecision] = useState({})
  const [motivo, setMotivo] = useState('')
  const [ocupado, setOcupado] = useState(false)
  const [mensaje, setMensaje] = useState(null)
  useMensajeTemporal(mensaje, setMensaje)

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

  const cerrarModal = () => {
    setRevisada(null)
    setModo(null)
    setMotivo('')
  }

  const abrirRevision = (d) => {
    setRevisada(d)
    setModo(null)
    setMotivo('')
    setDecision({ resolucion: 'REPOSICION', cantidad_recibida: d.cantidad, cantidad_apta: 0, cantidad_aprobada: d.cantidad })
  }

  const ejecutar = async (llamada) => {
    setOcupado(true)
    setMensaje(null)
    try {
      setMensaje({ tipo: 'exito', texto: (await llamada()).mensaje })
      lista.recargar()
      cerrarModal()
    } catch (falla) {
      setMensaje({ tipo: 'error', texto: Object.values(falla.errores || {})[0]?.[0] || falla.mensaje })
    } finally {
      setOcupado(false)
    }
  }

  const cambiarDecision = (e) => setDecision((actual) => ({ ...actual, [e.target.name]: e.target.value }))

  const aprobar = (e) => {
    e.preventDefault()
    if (!revisada) return
    ejecutar(() => devolucionesApi.aprobar(revisada.id, {
      resolucion: decision.resolucion,
      cantidad_recibida: Number(decision.cantidad_recibida),
      cantidad_apta: Number(decision.cantidad_apta),
      cantidad_aprobada: Number(decision.cantidad_aprobada),
    }))
  }

  const rechazar = (e) => {
    e.preventDefault()
    if (!revisada) return
    if (motivo.trim().length < 3) {
      setMensaje({ tipo: 'error', texto: 'El motivo debe tener al menos 3 caracteres.' })
      return
    }
    ejecutar(() => devolucionesApi.rechazar(revisada.id, motivo.trim()))
  }

  const filtros = (
    <>
      <label>Buscar<input type="search" name="q" defaultValue={valor('q')} maxLength={60} placeholder="Código, pedido, producto o cliente"
        onKeyDown={(e) => e.key === 'Enter' && filtrar({ q: e.currentTarget.value })} onBlur={(e) => filtrar({ q: e.target.value })} /></label>
      <label>Estado<select name="estado" value={valor('estado')} onChange={cambiar}>
        <option value="">Todos</option>
        {Object.entries(ESTADOS_DEVOLUCION).map(([c, t]) => <option key={c} value={c}>{t}</option>)}
      </select></label>
      <label>Resolución<select name="resolucion" value={valor('resolucion')} onChange={cambiar}>
        <option value="">Todas</option>
        {Object.entries(RESOLUCIONES).map(([c, t]) => <option key={c} value={c}>{t}</option>)}
      </select></label>
      <label>Desde<input type="date" name="desde" value={valor('desde')} onChange={cambiar} /></label>
      <label>Hasta<input type="date" name="hasta" value={valor('hasta')} onChange={cambiar} /></label>
    </>
  )

  const columnas = [
    { clave: 'codigo', etiqueta: 'Código', alRender: (d) => <>{d.codigo}<br /><small>{formatearFecha(d.fecha_solicitud)}</small></> },
    { clave: 'pedido', etiqueta: 'Pedido', alRender: (d) => <><Link to={`/gestion/pedidos/${d.pedido.id}`}>{d.pedido.codigo}</Link><br /><small>{d.cliente}</small></> },
    { clave: 'producto', etiqueta: 'Producto' },
    {
      clave: 'cantidad', etiqueta: 'Cantidad',
      alRender: (d) => <>{cantidadConUnidad(d.cantidad, d.unidad)}{d.cantidad_aprobada !== null && <><br /><small>Aprobadas: {cantidadConUnidad(d.cantidad_aprobada, d.unidad)} · Aptas: {cantidadConUnidad(d.cantidad_apta, d.unidad)}</small></>}</>,
    },
    { clave: 'motivo', etiqueta: 'Motivo', alRender: (d) => <small>{d.motivo}</small> },
    { clave: 'estado', etiqueta: 'Estado', alRender: (d) => <Insignia tono={tonoEstado(d.estado)}>{ESTADOS_DEVOLUCION[d.estado]}</Insignia> },
    { clave: 'resolucion', etiqueta: 'Resolución', alRender: (d) => <>{d.resolucion ? RESOLUCIONES[d.resolucion] : '—'}{d.importe !== null && <><br /><small>{formatearMoneda(d.importe)}</small></>}</> },
    {
      clave: 'accion', etiqueta: 'Acción',
      alRender: (d) => (d.estado === 'SOLICITADA' || d.estado === 'APROBADA'
        ? <Boton variante="secundario" onClick={() => abrirRevision(d)}>Revisar</Boton>
        : '—'),
    },
  ]

  return (
    <PaginaListado
      titulo="Devoluciones"
      descripcion="Abre «Revisar» para recibir el producto, aprobar con reposición o reintegro, rechazar con motivo, o completar una ya aprobada."
      etiquetaFiltros="Filtrar devoluciones"
      alLimpiarFiltros={() => setParametros({})}
      filtros={filtros}
      cargando={lista.cargando && !lista.datos}
      error={lista.error}
      resumen={lista.datos ? `${lista.datos.paginacion.total ?? 0} devoluciones` : null}
      vacio={lista.datos?.devoluciones.length === 0 ? { titulo: 'No hay devoluciones con esos filtros', mensaje: 'Prueba quitando algún filtro de estado o resolución.' } : null}
      paginacion={lista.datos?.devoluciones.length ? {
        pagina: lista.datos.paginacion.pagina,
        paginas: lista.datos.paginacion.paginas,
        alCambiar: (pagina) => filtrar({ pagina: String(pagina) }),
      } : null}
    >
      {mensaje && !revisada && <Alerta tipo={mensaje.tipo}>{mensaje.texto}</Alerta>}

      <TablaDatos columnas={columnas} filas={lista.datos?.devoluciones ?? []} />

      {revisada && (
        <ModalFlotante titulo={`Revisar devolución · ${revisada.codigo}`} alCerrar={cerrarModal} ancho="angosto">
          <dl className="gestion__detalle-modal">
            <dt>Pedido</dt><dd><Link to={`/gestion/pedidos/${revisada.pedido.id}`}>{revisada.pedido.codigo}</Link></dd>
            <dt>Cliente</dt><dd>{revisada.cliente}</dd>
            <dt>Producto</dt><dd>{revisada.producto}</dd>
            <dt>Solicitadas</dt><dd>{cantidadConUnidad(revisada.cantidad, revisada.unidad)}</dd>
            {revisada.cantidad_aprobada !== null && (
              <>
                <dt>Aprobadas</dt><dd>{cantidadConUnidad(revisada.cantidad_aprobada, revisada.unidad)}</dd>
                <dt>Aptas</dt><dd>{cantidadConUnidad(revisada.cantidad_apta, revisada.unidad)}</dd>
              </>
            )}
            <dt>Motivo</dt><dd>{revisada.motivo}</dd>
            <dt>Estado</dt><dd><Insignia tono={tonoEstado(revisada.estado)}>{ESTADOS_DEVOLUCION[revisada.estado]}</Insignia></dd>
            {revisada.resolucion && <><dt>Resolución</dt><dd>{RESOLUCIONES[revisada.resolucion]}</dd></>}
            {revisada.importe !== null && <><dt>Importe</dt><dd>{formatearMoneda(revisada.importe)}</dd></>}
          </dl>
          {mensaje && revisada && <Alerta tipo={mensaje.tipo}>{mensaje.texto}</Alerta>}
          {revisada.estado === 'SOLICITADA' && !modo && (
            <div className="gestion__botones">
              <Boton onClick={() => setModo('aprobar')}>Recibir y aprobar…</Boton>
              <Boton variante="peligro" onClick={() => { setModo('rechazar'); setMotivo('') }}>Rechazar…</Boton>
            </div>
          )}
          {revisada.estado === 'APROBADA' && !modo && (
            <Boton cargando={ocupado} onClick={() => ejecutar(() => devolucionesApi.completar(revisada.id))}>
              {revisada.resolucion === 'REEMBOLSO' ? 'Confirmar: ejecutar reintegro' : 'Confirmar: entregar reposición'}
            </Boton>
          )}
          {modo === 'aprobar' && revisada.estado === 'SOLICITADA' && (
            <form className="gestion__cancelar" noValidate onSubmit={aprobar} aria-label={`Aprobar ${revisada.codigo}`}>
              <p className="gestion__resumen">Las unidades aptas vuelven al stock de FEDER.</p>
              <label>Resolución<select name="resolucion" value={decision.resolucion} onChange={cambiarDecision}>
                {Object.entries(RESOLUCIONES).map(([c, t]) => <option key={c} value={c}>{t}</option>)}
              </select></label>
              <label>Recibidas<input type="number" name="cantidad_recibida" min="0" step="any" value={decision.cantidad_recibida} onChange={cambiarDecision} /></label>
              <label>Aptas para vender<input type="number" name="cantidad_apta" min="0" step="any" value={decision.cantidad_apta} onChange={cambiarDecision} /></label>
              <label>Aprobadas<input type="number" name="cantidad_aprobada" min="0" step="any" value={decision.cantidad_aprobada} onChange={cambiarDecision} /></label>
              <div className="gestion__botones">
                <Boton tipo="submit" cargando={ocupado}>Confirmar aprobación</Boton>
                <Boton variante="fantasma" onClick={() => setModo(null)}>Volver</Boton>
              </div>
            </form>
          )}
          {modo === 'rechazar' && revisada.estado === 'SOLICITADA' && (
            <form className="gestion__cancelar" noValidate onSubmit={rechazar}>
              <label htmlFor="motivo-rechazo-devolucion">Motivo del rechazo</label>
              <input id="motivo-rechazo-devolucion" value={motivo} maxLength={200} onChange={(e) => setMotivo(e.target.value)} />
              <div className="gestion__botones">
                <Boton tipo="submit" variante="peligro" cargando={ocupado}>Confirmar rechazo</Boton>
                <Boton variante="fantasma" onClick={() => { setModo(null); setMotivo('') }}>Volver</Boton>
              </div>
            </form>
          )}
        </ModalFlotante>
      )}
    </PaginaListado>
  )
}
