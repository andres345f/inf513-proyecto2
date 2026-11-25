import { useCallback, useRef, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { consultasApi } from '../../api/consultasApi'
import { productoresApi } from '../../api/productoresApi'
import Alerta from '../../components/ui/Alerta'
import Boton from '../../components/ui/Boton'
import DialogoConfirmacion from '../../components/ui/DialogoConfirmacion'
import Insignia from '../../components/ui/Insignia'
import PaginaListado from '../../components/plantillas/PaginaListado'
import { useCargaApi } from '../../hooks/useCargaApi'
import { useMensajeTemporal } from '../../hooks/useMensajeTemporal'
import { cantidadConUnidad, tonoEstado } from '../../utils/etiquetas'
import MensajeCampo from '../../components/ui/MensajeCampo'
import { formatearFecha, formatearFechaHora, formatearMoneda } from '../../utils/formato'
import {
  atributosError, enfocarPrimerError, erroresDeApi, fechaValida, longitudMaxima, numero, requerido, validar,
} from '../../utils/validaciones'
import './GestionPedidos.css'
import './CierreGestion.css'
import '../productor/Productor.css'

const ESTADOS = { REGISTRADA: 'Registrada', RECIBIDA_PARCIAL: 'Recibida parcialmente', RECIBIDA: 'Recibida', CANCELADA: 'Cancelada' }
const compraVacia = { lote_id: '', cantidad: '', precio: '', modalidad_traslado: 'ENTREGA_PRODUCTOR', fecha_acordada: '', responsable_traslado: '', telefono_traslado: '', observacion: '' }
const ESTADOS_RECEPCION = ['REGISTRADA', 'RECIBIDA_PARCIAL']
const CAMPOS_RECEPCION = { recibida: 'recibida', rechazada: 'rechazada', cancelada: 'cancelada' }

/** Reglas de la compra (reflejan CompraFederRequest); la cantidad no puede superar lo ofertado. */
function reglasCompra(lote) {
  const porUnidad = lote?.unidad_medida === 'UNIDAD'
  const disponible = lote ? Number(lote.disponible) : undefined
  const limite = lote ? ` y no superar lo disponible (${cantidadConUnidad(disponible, lote.unidad_medida)})` : ''
  return {
    lote_id: [requerido('Elige el lote ofrecido que vas a comprar.')],
    cantidad: [
      requerido('Indica la cantidad a comprar.'),
      numero({ mayorQue: 0, maximo: disponible ?? 999999999, decimales: 3, entero: porUnidad },
        `La cantidad debe ser mayor que cero${porUnidad ? ', en unidades enteras,' : ''}${limite}.`),
    ],
    precio: [requerido('Indica el precio unitario.'), numero({ mayorQue: 0, maximo: 9999999, decimales: 2 }, 'El precio debe ser mayor que cero, con hasta 2 decimales.')],
    fecha_acordada: [fechaValida('Ingresa una fecha acordada válida.')],
    responsable_traslado: [longitudMaxima(120, 'El nombre del responsable')],
    telefono_traslado: [longitudMaxima(20, 'El teléfono')],
    observacion: [longitudMaxima(1000, 'La observación')],
  }
}

/** Cada cantidad de la recepción es cero o más y, sumadas, no superan lo comprado. Claves «indice.campo». */
function validarRecepcion(recepcion, lineas) {
  const errores = {}
  recepcion.forEach((fila, i) => {
    const linea = lineas[i]
    const porUnidad = linea?.unidad_medida === 'UNIDAD'
    const regla = numero({ minimo: 0, decimales: 3, entero: porUnidad }, porUnidad ? 'Usa un número entero, cero o más.' : 'Usa un número, cero o más, con hasta 3 decimales.')
    Object.keys(CAMPOS_RECEPCION).forEach((campo) => {
      const valor = fila[campo]
      let mensaje = valor === '' || valor === null || valor === undefined ? 'Escribe una cantidad (0 si no aplica).' : regla(valor)
      if (!mensaje && linea && Number(valor) < Number(linea[campo])) {
        mensaje = `No puedes reducir lo ya registrado (${cantidadConUnidad(linea[campo], linea.unidad_medida)}).`
      }
      if (mensaje) errores[`${i}.${campo}`] = mensaje
    })
    const suma = Object.keys(CAMPOS_RECEPCION).reduce((total, campo) => total + (Number(fila[campo]) || 0), 0)
    if (!errores[`${i}.recibida`] && linea && suma > Number(linea.comprada) + 1e-9) {
      errores[`${i}.recibida`] = `Recibido, rechazado y cancelado suman más de lo comprado (${cantidadConUnidad(linea.comprada, linea.unidad_medida)}).`
    }
  })
  return errores
}

export default function ComprasProductoresPagina() {
  const [parametros, setParametros] = useSearchParams()
  const clave = parametros.toString()
  const lista = useCargaApi(() => consultasApi.comprasGestion(Object.fromEntries(parametros)), [clave])
  const ofertas = useCargaApi(() => productoresApi.lotesOfertados(), [])
  const [compra, setCompra] = useState(compraVacia)
  const [detalle, setDetalle] = useState(null)
  const [recepcion, setRecepcion] = useState([])
  const [mensaje, setMensaje] = useState('')
  const [error, setError] = useState('')
  const [erroresCompra, setErroresCompra] = useState({})
  const [erroresRecepcion, setErroresRecepcion] = useState({})
  const [ocupado, setOcupado] = useState(false)
  const [decision, setDecision] = useState(null)
  const ultimaCompraSolicitada = useRef(null)
  const tituloDetalle = useRef(null)
  const disparadorDetalle = useRef(null)
  const filtrar = useCallback((cambios) => setParametros((actuales) => {
    const nuevos = new URLSearchParams(actuales)
    Object.entries(cambios).forEach(([campo, valor]) => valor ? nuevos.set(campo, valor) : nuevos.delete(campo))
    if (!('pagina' in cambios)) nuevos.delete('pagina')
    return nuevos
  }), [setParametros])
  const valor = (campo) => parametros.get(campo) || ''

  useMensajeTemporal(mensaje, setMensaje, 4500, Boolean(mensaje))
  const ejecutar = async (accion, despues, alFallar = null) => {
    setOcupado(true); setError('')
    try {
      const r = await accion()
      setMensaje(r.message)
      await despues?.()
    } catch (falla) {
      setError(falla.mensaje)
      alFallar?.(falla)
    } finally {
      setOcupado(false)
    }
  }
  const abrir = async (id, disparador) => {
    if (disparador) disparadorDetalle.current = disparador
    ultimaCompraSolicitada.current = id
    setError('')
    try {
      const d = await productoresApi.detalleCompra(id)
      if (ultimaCompraSolicitada.current !== id) return
      setDetalle(d)
      if (disparador) requestAnimationFrame(() => tituloDetalle.current?.focus())
      setRecepcion(d.lineas.map((l) => ({ detalle_id: l.id, recibida: l.recibida, rechazada: l.rechazada, cancelada: l.cancelada })))
      setErroresRecepcion({})
    } catch (falla) { if (ultimaCompraSolicitada.current === id) setError(falla.mensaje) }
  }
  const actualizarTodo = async (id = detalle?.id) => {
    lista.recargar(); ofertas.recargar()
    if (id) await abrir(id)
  }
  const seleccionarLote = (id) => {
    const l = ofertas.datos?.lotes.find((x) => String(x.id) === id)
    setCompra((v) => ({ ...v, lote_id: id, cantidad: l ? String(l.disponible) : '', precio: l?.precio_ofrecido == null ? '' : String(l.precio_ofrecido) }))
    setErroresCompra((actual) => ({ ...actual, lote_id: null, cantidad: null, precio: null }))
  }
  const loteElegido = ofertas.datos?.lotes.find((x) => String(x.id) === String(compra.lote_id))
  const cambiarCompra = (campo, valor) => {
    setCompra((v) => ({ ...v, [campo]: valor }))
    setErroresCompra((actual) => (actual[campo] ? { ...actual, [campo]: null } : actual))
  }
  const registrar = (e) => {
    e.preventDefault()
    const formularioHtml = e.currentTarget
    const erroresCliente = validar(compra, reglasCompra(loteElegido))
    setErroresCompra(erroresCliente)
    if (Object.keys(erroresCliente).length > 0) {
      setError('Revisa los campos marcados de la compra.')
      enfocarPrimerError(formularioHtml)
      return
    }
    const payload = { ...compra, lineas: [{ lote_id: Number(compra.lote_id), cantidad: compra.cantidad, precio: compra.precio }] }
    delete payload.lote_id; delete payload.cantidad; delete payload.precio
    ejecutar(() => productoresApi.crearCompra(payload), async () => { setCompra(compraVacia); actualizarTodo(null) }, (falla) => {
      // La API valida «lineas.0.cantidad»; se muestra bajo el campo correspondiente.
      const deApi = erroresDeApi(falla)
      setErroresCompra({
        ...deApi,
        lote_id: deApi['lineas.0.lote_id'] ?? deApi.lote_id,
        cantidad: deApi['lineas.0.cantidad'] ?? deApi.cantidad,
        precio: deApi['lineas.0.precio'] ?? deApi.precio,
      })
      enfocarPrimerError(formularioHtml)
    })
  }
  const recibir = (e) => {
    e.preventDefault()
    const formularioHtml = e.currentTarget
    const erroresCliente = validarRecepcion(recepcion, detalle.lineas)
    setErroresRecepcion(erroresCliente)
    if (Object.keys(erroresCliente).length > 0) {
      setError('Revisa las cantidades de la recepción.')
      enfocarPrimerError(formularioHtml)
      return
    }
    ejecutar(() => productoresApi.recibirCompra(detalle.id, { lineas: recepcion, ubicacion_almacen: 'Almacén principal' }), () => actualizarTodo())
  }
  const cambiarRecepcion = (i, campo, valor) => {
    setRecepcion((actual) => actual.map((l, indice) => indice === i ? { ...l, [campo]: valor } : l))
    setErroresRecepcion((actual) => (actual[`${i}.${campo}`] ? { ...actual, [`${i}.${campo}`]: null } : actual))
  }
  const accesibleCompra = (campo) => atributosError(erroresCompra, campo, `compra-${campo}-error`)

  return <PaginaListado
    titulo="Compras a productores" descripcion="Selecciona ofertas, acuerda el traslado, recibe los lotes y registra el pago."
    etiquetaFiltros="Filtrar compras a productores" alLimpiarFiltros={() => setParametros({})}
    cargando={(lista.cargando && !lista.datos) || (ofertas.cargando && !ofertas.datos)}
    actualizando={lista.actualizando || ofertas.actualizando}
    resumen={lista.datos ? `${lista.datos.paginacion.total} ${lista.datos.paginacion.total === 1 ? 'compra registrada' : 'compras registradas'}` : null}
    paginacion={lista.datos?.compras.length ? { pagina: lista.datos.paginacion.pagina, paginas: lista.datos.paginacion.paginas,
      alCambiar: (pagina) => filtrar({ pagina: String(pagina) }) } : null}
    filtros={<>
      <label>Buscar<input defaultValue={valor('q')} placeholder="Compra o productor" maxLength={60} onBlur={(e) => e.target.value !== valor('q') && filtrar({ q: e.target.value })} onKeyDown={(e) => e.key === 'Enter' && e.currentTarget.blur()} /></label>
      <label>Estado<select value={valor('estado')} onChange={(e) => filtrar({ estado: e.target.value })}><option value="">Todos</option>{Object.entries(ESTADOS).map(([codigo, texto]) => <option key={codigo} value={codigo}>{texto}</option>)}</select></label>
      <label>Pago<select value={valor('pago')} onChange={(e) => filtrar({ pago: e.target.value })}><option value="">Todos</option><option value="PENDIENTE">Pendiente</option><option value="PAGADA">Pagada</option></select></label>
      <label>Desde<input type="date" value={valor('desde')} onChange={(e) => filtrar({ desde: e.target.value })} /></label>
      <label>Hasta<input type="date" min={valor('desde') || undefined} value={valor('hasta')} onChange={(e) => filtrar({ hasta: e.target.value })} /></label>
    </>}
  >
    {decision && <DialogoConfirmacion {...decision} alCerrar={() => setDecision(null)} />}
    {(lista.error || ofertas.error || error) && <Alerta tipo="error">{lista.error || ofertas.error || error}</Alerta>}{mensaje && <Alerta tipo="exito">{mensaje}</Alerta>}

    <details className="productor__panel">
      <summary>Registrar compra desde una oferta</summary>
      <form className="productor__formulario" onSubmit={registrar} noValidate>
        <label htmlFor="compra-lote">Lote ofrecido
          <select id="compra-lote" aria-required="true" value={compra.lote_id} onChange={(e) => seleccionarLote(e.target.value)} {...accesibleCompra('lote_id')}>
            <option value="">Selecciona…</option>
            {ofertas.datos?.lotes.map((l) => (
              <option key={l.id} value={l.id}>{l.productor} · {l.producto} · {l.codigo} · {cantidadConUnidad(l.disponible, l.unidad_medida)}</option>
            ))}
          </select>
          <MensajeCampo id="compra-lote_id-error" error={erroresCompra.lote_id} />
        </label>
        <div className="productor__rejilla">
          <label htmlFor="compra-cantidad">Cantidad a comprar
            <input id="compra-cantidad" type="number" aria-required="true" min="0.001" step={loteElegido?.unidad_medida === 'UNIDAD' ? '1' : '0.001'} inputMode="decimal"
              value={compra.cantidad} onChange={(e) => cambiarCompra('cantidad', e.target.value)} {...accesibleCompra('cantidad')} />
            <MensajeCampo id="compra-cantidad-error" error={erroresCompra.cantidad} />
          </label>
          <label htmlFor="compra-precio">Precio unitario (Bs)
            <input id="compra-precio" type="number" aria-required="true" min="0.01" step="0.01" inputMode="decimal"
              value={compra.precio} onChange={(e) => cambiarCompra('precio', e.target.value)} {...accesibleCompra('precio')} />
            <MensajeCampo id="compra-precio-error" error={erroresCompra.precio} />
          </label>
          <label htmlFor="compra-traslado">Traslado
            <select id="compra-traslado" value={compra.modalidad_traslado} onChange={(e) => cambiarCompra('modalidad_traslado', e.target.value)}>
              <option value="ENTREGA_PRODUCTOR">Entrega el productor</option>
              <option value="RECOJO_FEDER">Recoge FEDER</option>
            </select>
          </label>
          <label htmlFor="compra-fecha">Fecha acordada
            <input id="compra-fecha" type="date" value={compra.fecha_acordada} onChange={(e) => cambiarCompra('fecha_acordada', e.target.value)} {...accesibleCompra('fecha_acordada')} />
            <MensajeCampo id="compra-fecha_acordada-error" error={erroresCompra.fecha_acordada} />
          </label>
          <label htmlFor="compra-responsable">Responsable del traslado
            <input id="compra-responsable" maxLength={120} value={compra.responsable_traslado} onChange={(e) => cambiarCompra('responsable_traslado', e.target.value)} {...accesibleCompra('responsable_traslado')} />
            <MensajeCampo id="compra-responsable_traslado-error" error={erroresCompra.responsable_traslado} />
          </label>
          <label htmlFor="compra-telefono">Teléfono
            <input id="compra-telefono" type="tel" maxLength={20} value={compra.telefono_traslado} onChange={(e) => cambiarCompra('telefono_traslado', e.target.value)} {...accesibleCompra('telefono_traslado')} />
            <MensajeCampo id="compra-telefono_traslado-error" error={erroresCompra.telefono_traslado} />
          </label>
        </div>
        <label htmlFor="compra-observacion">Observación
          <textarea id="compra-observacion" rows={2} maxLength={1000} value={compra.observacion} onChange={(e) => cambiarCompra('observacion', e.target.value)} {...accesibleCompra('observacion')} />
          <MensajeCampo id="compra-observacion-error" error={erroresCompra.observacion} />
        </label>
        <Boton tipo="submit" cargando={ocupado}>Registrar compra</Boton>
      </form>
    </details>

    <div className="cierre-gestion__lista">{lista.datos?.compras.map((c) => <article key={c.id} className="cierre-gestion__tarjeta">
      <div className="cierre-gestion__resumen-compra">
        <div className="cierre-gestion__identidad">
          <strong className="cierre-gestion__codigo">{c.codigo}</strong>
          <span className="cierre-gestion__productor">{c.productor}</span>
          <small>{c.codigo_productor} · {formatearFechaHora(c.fecha)}</small>
        </div>
        <div className="cierre-gestion__estado-compra">
          <Insignia tono={tonoEstado(c.estado)}>{ESTADOS[c.estado] || c.estado}</Insignia>
          <strong className="cierre-gestion__total">{formatearMoneda(c.total)}</strong>
        </div>
        <Boton variante="secundario" className="cierre-gestion__accion" aria-label={`Ver y gestionar compra ${c.codigo}`} onClick={(evento) => abrir(c.id, evento.currentTarget)}>Ver y gestionar</Boton>
      </div>
    </article>)}</div>

    {detalle && (
      <section className="productor__panel" aria-label={`Compra ${detalle.codigo}`}>
        <div className="productor__fila-acciones">
          <div>
            <h2 ref={tituloDetalle} tabIndex={-1}>{detalle.codigo} · {detalle.productor.nombre}</h2>
            <p>
              {detalle.logistica?.modalidad === 'RECOJO_FEDER' ? 'FEDER recoge el producto' : 'El productor lleva el producto'}
              {detalle.logistica?.responsable ? ` · Responsable: ${detalle.logistica.responsable}` : ''}
            </p>
          </div>
          <Boton variante="fantasma" onClick={() => { ultimaCompraSolicitada.current = null; setDetalle(null); setErroresRecepcion({}); disparadorDetalle.current?.focus() }}>Cerrar</Boton>
        </div>
        <dl className="productor__datos">
          <dt>Estado</dt><dd>{ESTADOS[detalle.estado] || detalle.estado}</dd>
          <dt>Total</dt><dd>{formatearMoneda(detalle.total)}</dd>
          <dt>Pago</dt><dd>{detalle.pagada_el ? formatearFechaHora(detalle.pagada_el) : 'Pendiente'}</dd>
          <dt>Fecha acordada</dt><dd>{detalle.logistica?.fecha_acordada ? formatearFecha(detalle.logistica.fecha_acordada) : 'Sin fecha'}</dd>
        </dl>
        <form className="productor__formulario" onSubmit={recibir} noValidate>
          <h3>{ESTADOS_RECEPCION.includes(detalle.estado) ? 'Recepción en almacén' : 'Detalle de la recepción'}</h3>
          <div className="gestion__tabla-contenedor" tabIndex={0} role="region" aria-label={`Líneas de recepción de ${detalle.codigo}`}>
            <table className="gestion__tabla">
              <thead>
                <tr>
                  <th scope="col">Producto</th><th scope="col">Comprado</th><th scope="col">Precio</th>
                  <th scope="col">Recibido</th><th scope="col">Rechazado</th><th scope="col">Cancelado</th>
                </tr>
              </thead>
              <tbody>
                {detalle.lineas.map((l, i) => (
                  <tr key={l.id}>
                    <td>{l.producto}<small>{l.lote}</small></td>
                    <td>{cantidadConUnidad(l.comprada, l.unidad_medida)}</td>
                    <td>{formatearMoneda(l.precio)}</td>
                    {Object.keys(CAMPOS_RECEPCION).map((campo) => (
                      <td key={campo}>
                        {ESTADOS_RECEPCION.includes(detalle.estado) ? (
                          <>
                            <input
                              aria-label={`Cantidad ${CAMPOS_RECEPCION[campo]} de ${l.producto} (${l.lote})`}
                              type="number" min="0" max={l.comprada} step={l.unidad_medida === 'UNIDAD' ? '1' : '0.001'} inputMode="decimal"
                              value={recepcion[i]?.[campo] ?? 0}
                              onChange={(e) => cambiarRecepcion(i, campo, e.target.value)}
                              {...atributosError(erroresRecepcion, `${i}.${campo}`, `recepcion-${i}-${campo}-error`)}
                            />
                            <MensajeCampo id={`recepcion-${i}-${campo}-error`} error={erroresRecepcion[`${i}.${campo}`]} />
                          </>
                        ) : cantidadConUnidad(l[campo], l.unidad_medida)}
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {ESTADOS_RECEPCION.includes(detalle.estado) && <Boton tipo="submit" cargando={ocupado}>Guardar recepción</Boton>}
        </form>
        {detalle.estado === 'RECIBIDA' && !detalle.pagada_el && (
          <div className="productor__acciones">
            <Boton cargando={ocupado} onClick={() => setDecision({
              titulo: 'Registrar pago al productor', accion: 'Confirmar registro del pago',
              mensaje: `Se registrará el pago de ${formatearMoneda(detalle.total)} por la compra ${detalle.codigo} a ${detalle.productor.nombre}.`,
              alConfirmar: () => ejecutar(() => productoresApi.pagarCompra(detalle.id), () => actualizarTodo()),
            })}>Registrar pago al productor</Boton>
          </div>
        )}
      </section>
    )}
  </PaginaListado>
}
