import { useState } from 'react'
import { productoresApi } from '../../api/productoresApi'
import Alerta from '../../components/ui/Alerta'
import Boton from '../../components/ui/Boton'
import Cargando from '../../components/ui/Cargando'
import Insignia from '../../components/ui/Insignia'
import MensajeCampo from '../../components/ui/MensajeCampo'
import EncabezadoPagina from '../../components/plantillas/EncabezadoPagina'
import EstadoVacio from '../../components/plantillas/EstadoVacio'
import { useCargaApi } from '../../hooks/useCargaApi'
import { useMensajeTemporal } from '../../hooks/useMensajeTemporal'
import { formatearFecha } from '../../utils/formato'
import {
  atributosError, enfocarPrimerError, erroresDeApi, longitudMaxima, longitudMinima, requerido, validar,
} from '../../utils/validaciones'
import './Productor.css'

const ESTADO_PROPUESTA = { PENDIENTE: 'En revisión por FEDER', APROBADA: 'Aprobada', RECHAZADA: 'Rechazada' }
const TONO = { PENDIENTE: 'aviso', APROBADA: 'exito', RECHAZADA: 'error' }
const propuestaVacia = { nombre: '', descripcion: '', presentacion: '', unidad_medida: 'UNIDAD', categoria_sugerida: '' }
const UNIDADES = ['UNIDAD', 'KILOGRAMO', 'LIBRA', 'ARROBA', 'LITRO']

// Reglas por paso (reflejan PropuestaProductoRequest): no se avanza ni se envía con datos incompletos.
const REGLAS_PASO = [
  {
    nombre: [requerido('Escribe el nombre del producto.'), longitudMinima(3, 'El nombre debe tener al menos 3 caracteres.'), longitudMaxima(120, 'El nombre')],
    presentacion: [requerido('Escribe la presentación, por ejemplo «Frasco de 250 g».'), longitudMinima(3, 'La presentación debe tener al menos 3 caracteres.'), longitudMaxima(60, 'La presentación')],
    unidad_medida: [requerido('Elige la unidad de venta.')],
  },
  {
    categoria_sugerida: [requerido('Sugiere una categoría para el producto.'), longitudMinima(3, 'La categoría debe tener al menos 3 caracteres.'), longitudMaxima(100, 'La categoría')],
    descripcion: [requerido('Describe el producto.'), longitudMinima(10, 'La descripción debe tener al menos 10 caracteres.'), longitudMaxima(1000, 'La descripción')],
  },
]
const pasoDelCampo = (campo) => (campo in REGLAS_PASO[0] ? 0 : 1)

/* Propuestas de productos nuevos para FEDER: envío y seguimiento del estado.
   Ruta: /productor/propuestas  ·  Permiso: producciones.propias */
export default function PropuestasPagina() {
  const propuestas = useCargaApi(() => productoresApi.propuestas(), [])
  const [pasoActual, setPasoActual] = useState(0)
  const [formulario, setFormulario] = useState(propuestaVacia)
  const [mensaje, setMensaje] = useState('')
  const [error, setError] = useState('')
  const [errores, setErrores] = useState({})
  const [ocupado, setOcupado] = useState(false)

  useMensajeTemporal(mensaje, setMensaje, 5000, Boolean(mensaje))

  /** Valida los pasos indicados; si hay errores, vuelve al primer paso con error y enfoca el campo. */
  const validarPasos = (pasos, formularioHtml) => {
    const reglas = Object.assign({}, ...pasos.map((indice) => REGLAS_PASO[indice]))
    const erroresCliente = validar(formulario, reglas)
    setErrores(erroresCliente)
    const campos = Object.keys(erroresCliente)
    if (campos.length === 0) return true
    setError('Revisa los campos marcados.')
    setPasoActual(Math.min(...campos.map(pasoDelCampo)))
    enfocarPrimerError(formularioHtml)
    return false
  }
  const continuar = (formularioHtml) => {
    if (!validarPasos([0], formularioHtml)) return
    setError('')
    setPasoActual(1)
  }
  const enviar = (formularioHtml) => {
    if (!validarPasos([0, 1], formularioHtml)) return
    setOcupado(true)
    setError('')
    productoresApi.proponerProducto(formulario)
      .then((r) => {
        setMensaje(r.message)
        setFormulario(propuestaVacia)
        setErrores({})
        setPasoActual(0)
        return propuestas.recargar()
      })
      .catch((falla) => {
        const deApi = erroresDeApi(falla)
        setErrores(deApi)
        setError(falla.mensaje)
        const campos = Object.keys(deApi)
        if (campos.length) setPasoActual(Math.min(...campos.map(pasoDelCampo)))
        enfocarPrimerError(formularioHtml)
      })
      .finally(() => setOcupado(false))
  }
  const cambiar = (campo, valor) => {
    setFormulario((v) => ({ ...v, [campo]: valor }))
    setErrores((actual) => (actual[campo] ? { ...actual, [campo]: null } : actual))
  }

  if (propuestas.cargando && !propuestas.datos) return <Cargando texto="Cargando tus propuestas…" />

  const lista = propuestas.datos?.propuestas ?? []
  const paso1 = formularioPaso1(formulario, cambiar, errores)
  const paso2 = formularioPaso2(formulario, cambiar, errores)

  return (
    <section className="productor">
      <EncabezadoPagina
        titulo="Proponer un producto"
        descripcion="¿Tu producto no está en el catálogo de FEDER? Envíalo para revisión y sigue su estado aquí."
      />

      {error && <Alerta tipo="error">{error}</Alerta>}
      {mensaje && <Alerta tipo="exito">{mensaje}</Alerta>}

      <section className="productor__panel" aria-label="Formulario de propuesta" aria-busy={ocupado}>
        <div className="productor__pasos" role="tablist" aria-label="Pasos de la propuesta">
          {['Datos del producto', 'Detalles y envío'].map((nombre, indice) => (
            <button
              key={nombre}
              type="button"
              role="tab"
              aria-selected={pasoActual === indice}
              className={`productor__paso${pasoActual === indice ? ' productor__paso--activo' : ''}`}
              onClick={() => (indice === 1 ? continuar(document.getElementById('form-propuesta')) : setPasoActual(0))}
            >
              {indice + 1}. {nombre}
            </button>
          ))}
        </div>

        <form
          id="form-propuesta"
          className="productor__formulario"
          noValidate
          onSubmit={(e) => {
            e.preventDefault()
            if (pasoActual === 1) enviar(e.currentTarget)
            else continuar(e.currentTarget)
          }}
        >
          {pasoActual === 0 ? paso1 : paso2}
          <div className="productor__acciones">
            {pasoActual > 0 && <Boton variante="fantasma" onClick={() => setPasoActual(0)} disabled={ocupado}>‹ Atrás</Boton>}
            {pasoActual === 0
              ? <Boton tipo="submit">Continuar ›</Boton>
              : <Boton tipo="submit" cargando={ocupado}>Enviar a revisión</Boton>}
          </div>
        </form>
      </section>

      <h2>Propuestas enviadas</h2>
      {lista.length === 0 ? (
        <EstadoVacio
          titulo="Sin propuestas todavía"
          mensaje="Cuando envíes una propuesta verás aquí su estado de revisión."
        />
      ) : (
        <ul className="productor__propuestas">
          {lista.map((propuesta) => (
            <li key={propuesta.id} className="productor__propuesta">
              <div>
                <strong>{propuesta.nombre}</strong>
                <p>{propuesta.presentacion} · categoría sugerida: {propuesta.categoria_sugerida}</p>
                {propuesta.motivo_rechazo && <p className="productor__propuesta-motivo">Motivo del rechazo: {propuesta.motivo_rechazo}</p>}
              </div>
              <div className="productor__propuesta-meta">
                <Insignia tono={TONO[propuesta.estado] ?? 'info'}>{ESTADO_PROPUESTA[propuesta.estado] ?? propuesta.estado}</Insignia>
                {propuesta.creada_en && <time>{formatearFecha(propuesta.creada_en)}</time>}
              </div>
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}

function formularioPaso1(valores, cambiar, errores) {
  const accesible = (campo) => atributosError(errores, campo, `propuesta-${campo}-error`)
  return (
    <fieldset className="productor__rejilla">
      <label htmlFor="propuesta-nombre">Nombre del producto
        <input id="propuesta-nombre" aria-required="true" maxLength={120} value={valores.nombre} onChange={(e) => cambiar('nombre', e.target.value)} {...accesible('nombre')} />
        <MensajeCampo id="propuesta-nombre-error" error={errores.nombre} />
      </label>
      <label htmlFor="propuesta-presentacion">Presentación
        <input id="propuesta-presentacion" aria-required="true" maxLength={60} placeholder="Frasco de 250 g" value={valores.presentacion} onChange={(e) => cambiar('presentacion', e.target.value)} {...accesible('presentacion')} />
        <MensajeCampo id="propuesta-presentacion-error" error={errores.presentacion} />
      </label>
      <label htmlFor="propuesta-unidad">Unidad de venta
        <select id="propuesta-unidad" value={valores.unidad_medida} onChange={(e) => cambiar('unidad_medida', e.target.value)} {...accesible('unidad_medida')}>
          {UNIDADES.map((u) => <option key={u}>{u}</option>)}
        </select>
        <MensajeCampo id="propuesta-unidad_medida-error" error={errores.unidad_medida} />
      </label>
    </fieldset>
  )
}

function formularioPaso2(valores, cambiar, errores) {
  const accesible = (campo) => atributosError(errores, campo, `propuesta-${campo}-error`)
  return (
    <fieldset>
      <label htmlFor="propuesta-categoria">Categoría sugerida
        <input id="propuesta-categoria" aria-required="true" maxLength={100} value={valores.categoria_sugerida} onChange={(e) => cambiar('categoria_sugerida', e.target.value)} {...accesible('categoria_sugerida')} />
        <MensajeCampo id="propuesta-categoria_sugerida-error" error={errores.categoria_sugerida} />
      </label>
      <label htmlFor="propuesta-descripcion">Descripción
        <textarea id="propuesta-descripcion" aria-required="true" rows={3} maxLength={1000} value={valores.descripcion} onChange={(e) => cambiar('descripcion', e.target.value)} {...accesible('descripcion')} />
        <MensajeCampo id="propuesta-descripcion-error" error={errores.descripcion} />
        <small>Mínimo 10 caracteres: cuéntale a FEDER qué produce tu comunidad y por qué interesa.</small>
      </label>
    </fieldset>
  )
}