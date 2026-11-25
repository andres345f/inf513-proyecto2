import { useEffect, useMemo, useRef, useState } from 'react'
import { rutaPublica } from '../../utils/rutas'
import Alerta from '../ui/Alerta'
import Boton from '../ui/Boton'
import CampoTexto from '../ui/CampoTexto'
import Casilla from '../ui/Casilla'
import { useFormulario } from '../../hooks/useFormulario'
import { UNIDADES } from '../../utils/etiquetas'
import { longitudMaxima, patron, requerido } from '../../utils/validaciones'
import './FormularioGestion.css'

const numeroPositivo = (mensaje) => (v) => (v !== '' && v != null && !(Number(v) > 0) ? mensaje : null)
const numeroNoNegativo = (mensaje) => (v) => (v !== '' && v != null && !(Number(v) >= 0) ? mensaje : null)

const REGLAS = {
  categoria_id: [requerido('Elige la categoría.')],
  codigo: [requerido('Escribe el código.'), patron(/^[A-Za-z0-9-]+$/, 'Solo letras, números y guiones.'), longitudMaxima(30, 'El código')],
  nombre: [requerido('Escribe el nombre.'), longitudMaxima(120, 'El nombre')],
  presentacion: [requerido('Describe la presentación (por ejemplo, Frasco de 500 g).'), longitudMaxima(60, 'La presentación')],
  precio_venta: [requerido('Indica el precio.'), numeroPositivo('El precio debe ser mayor que cero.')],
  stock_minimo: [requerido('Indica el stock mínimo.'), numeroNoNegativo('El stock mínimo no puede ser negativo.')],
  peso_kg: [numeroPositivo('El peso debe ser mayor que cero.')],
}

/** Alta o edición de un producto, incluido su peso por unidad (para el cálculo del envío). */
export default function FormularioProducto({ producto, categorias, alGuardar, alCancelar, guardar }) {
  const iniciales = useMemo(() => ({
    categoria_id: producto?.categoria_id ?? '',
    codigo: producto?.codigo ?? '',
    nombre: producto?.nombre ?? '',
    descripcion: producto?.descripcion ?? '',
    presentacion: producto?.presentacion ?? '',
    unidad_medida: producto?.unidad_medida ?? 'UNIDAD',
    precio_venta: producto?.precio_venta ?? '',
    stock_minimo: producto?.stock_minimo ?? 0,
    peso_kg: producto?.peso_kg ?? '',
    permite_encargo: producto?.permite_encargo ?? false,
    activo: producto?.activo ?? true,
  }), [producto])

  const { valores, errores, errorGeneral, enviando, cambiar, validarCampo, enviar } = useFormulario(iniciales, REGLAS)
  const p = (nombre) => ({ nombre, value: valores[nombre], onChange: cambiar, onBlur: validarCampo, error: errores[nombre] })

  const [seleccionFoto, setSeleccionFoto] = useState(null)
  const [retirar, setRetirar] = useState(false)
  const [errorLocalFoto, setErrorLocalFoto] = useState('')
  const [comprobandoFoto, setComprobandoFoto] = useState(false)
  const [fotoAusente, setFotoAusente] = useState(false)
  const [ignorarErrorAnterior, setIgnorarErrorAnterior] = useState(false)
  const solicitudFoto = useRef(0)
  const archivo = useRef(null)
  const formulario = useRef(null)
  const titulo = useRef(null)
  useEffect(() => { titulo.current?.focus() }, [])
  useEffect(() => () => {
    if (seleccionFoto?.vista) URL.revokeObjectURL(seleccionFoto.vista)
  }, [seleccionFoto])

  const foto = seleccionFoto?.archivo
  const imagen = seleccionFoto?.vista || (!retirar && rutaPublica(producto?.imagen_url))
  const errorFoto = errorLocalFoto || (!ignorarErrorAnterior && errores.foto)
  const cambiarFoto = async (evento) => {
    const seleccion = evento.target.files?.[0]
    if (!seleccion) return
    const turno = ++solicitudFoto.current
    setIgnorarErrorAnterior(true)
    setComprobandoFoto(false)
    setRetirar(false)
    setFotoAusente(false)
    let mensaje = ''
    if (seleccion.type && !['image/jpeg', 'image/png'].includes(seleccion.type)) mensaje = 'Elige un archivo JPEG o PNG.'
    else if (seleccion.size > 5 * 1024 * 1024) mensaje = 'La foto admite un máximo de 5 MB.'
    const vista = mensaje ? null : URL.createObjectURL(seleccion)
    setSeleccionFoto({ archivo: seleccion, vista })
    setErrorLocalFoto(mensaje)
    if (mensaje) return
    setComprobandoFoto(true)
    const prueba = new Image()
    prueba.src = vista
    try {
      await prueba.decode()
      if (prueba.naturalWidth > 4096 || prueba.naturalHeight > 4096 || prueba.naturalWidth * prueba.naturalHeight > 8000000) {
        mensaje = 'Elige una foto de hasta 4096 píxeles por lado y 8 megapíxeles.'
      }
    } catch {
      mensaje = 'No pudimos leer la foto. Elige otro archivo JPEG o PNG.'
    }
    if (solicitudFoto.current === turno) {
      setErrorLocalFoto(mensaje)
      setComprobandoFoto(false)
    }
  }
  const descartarSeleccion = () => {
    solicitudFoto.current += 1
    archivo.current.value = ''
    setSeleccionFoto(null)
    setComprobandoFoto(false)
    setErrorLocalFoto('')
    setIgnorarErrorAnterior(true)
    setFotoAusente(false)
    setRetirar(false)
  }

  const alEnviar = enviar(async (datos) => {
    if (comprobandoFoto || errorLocalFoto) {
      throw { mensaje: 'Revisa la foto principal.', errores: { foto: [errorLocalFoto || 'Espera a que se compruebe la foto.'] } }
    }
    setIgnorarErrorAnterior(false)
    const imagenElegida = foto ? { foto } : retirar ? { retirar_foto: true } : {}
    const r = await guardar({ ...datos, ...imagenElegida, peso_kg: datos.peso_kg === '' ? null : Number(datos.peso_kg) })
    alGuardar(r.message)
  })

  return (
    <form ref={formulario} className="formulario-gestion" onSubmit={alEnviar} noValidate aria-label={producto ? 'Editar producto' : 'Nuevo producto'}>
      <h2 ref={titulo} tabIndex={-1}>{producto ? `Editar ${producto.nombre}` : 'Nuevo producto'}</h2>
      {errorGeneral && <Alerta tipo="error">{errorGeneral} {Object.values(errores).some(Boolean) && (
        <button type="button" className="formulario-gestion__ir-error"
          onClick={() => formulario.current?.querySelector('[aria-invalid="true"]')?.focus()}>
          Revisar campos marcados ({Object.values(errores).filter(Boolean).length})
        </button>
      )}</Alerta>}
      <div className="formulario-gestion__rejilla">
        <div className="formulario-gestion__selector">
          <label htmlFor="producto-categoria">Categoría <span aria-hidden="true">*</span></label>
          <select id="producto-categoria" name="categoria_id" value={valores.categoria_id} onChange={cambiar} aria-required="true" aria-invalid={Boolean(errores.categoria_id)} aria-describedby={errores.categoria_id ? "producto-categoria-error" : undefined}>
            <option value="">Elige una categoría</option>
            {categorias.map((c) => <option key={c.id} value={c.id}>{c.nombre}</option>)}
          </select>
          {errores.categoria_id && <p id="producto-categoria-error" className="formulario-gestion__error" role="alert">{errores.categoria_id}</p>}
        </div>
        <CampoTexto etiqueta="Código" obligatorio maxLength={30} {...p('codigo')} />
        <CampoTexto etiqueta="Nombre" obligatorio maxLength={120} {...p('nombre')} />
        <CampoTexto etiqueta="Presentación" obligatorio maxLength={60} {...p('presentacion')} />
        <div className="formulario-gestion__selector">
          <label htmlFor="producto-unidad">Se vende por</label>
          <select id="producto-unidad" name="unidad_medida" value={valores.unidad_medida} onChange={cambiar}>
            {Object.entries(UNIDADES).map(([c, u]) => <option key={c} value={c}>{u.larga}</option>)}
          </select>
        </div>
        <CampoTexto etiqueta="Precio (Bs)" inputMode="decimal" obligatorio {...p('precio_venta')} />
        <CampoTexto etiqueta="Stock mínimo" inputMode="decimal" obligatorio ayuda="Debajo de esto se muestra la alerta de stock bajo." {...p('stock_minimo')} />
        <CampoTexto etiqueta="Peso por unidad (kg)" opcional inputMode="decimal" ayuda="Se usa para elegir moto o auto en el envío." {...p('peso_kg')} />
        <div className="formulario-gestion__ancho">
          <CampoTexto etiqueta="Descripción" opcional maxLength={1000} {...p('descripcion')} />
        </div>
        <div className="formulario-gestion__ancho formulario-gestion__selector">
          <label htmlFor="producto-foto">Foto principal <small>(opcional)</small></label>
          <div className="formulario-gestion__elegir-foto">
            <span aria-hidden="true">{imagen || foto ? 'Reemplazar foto' : 'Seleccionar foto'}</span>
            <input ref={archivo} id="producto-foto" name="foto" type="file" accept="image/jpeg,image/png"
            onChange={cambiarFoto} aria-describedby={errorFoto ? 'producto-foto-ayuda producto-foto-error' : 'producto-foto-ayuda'}
            aria-invalid={Boolean(errorFoto)} />
          </div>
          <small id="producto-foto-ayuda">JPEG o PNG, hasta 5 MB, 4096 píxeles por lado y 8 megapíxeles.</small>
          {foto && <p className="formulario-gestion__archivo">{foto.name} · {new Intl.NumberFormat('es-BO', { maximumFractionDigits: 1 }).format(foto.size / 1024)} KB</p>}
          {comprobandoFoto && <span role="status">Comprobando la foto…</span>}
          {errorFoto && <p id="producto-foto-error" role="alert" className="formulario-gestion__error">{errorFoto}</p>}
          {imagen && !fotoAusente && !errorLocalFoto && (
            <div className="formulario-gestion__recortes">
              {['Tarjeta · 4:3', 'Detalle · 1:1'].map((titulo, indice) => (
                <figure key={titulo}>
                  <img className={indice === 0 ? 'formulario-gestion__tarjeta-foto' : 'formulario-gestion__detalle-foto'}
                    src={imagen} alt={indice === 0 ? 'Vista previa de la foto principal' : 'Recorte cuadrado de la foto principal'}
                    onError={() => setFotoAusente(true)} />
                  <figcaption>{titulo}</figcaption>
                </figure>
              ))}
            </div>
          )}
          {fotoAusente && <span>Foto no disponible</span>}
          {retirar && <span role="status">La foto se retirará al guardar.</span>}
          <div className="formulario-gestion__botones-foto">
            {(foto || retirar) && <Boton variante="secundario" onClick={descartarSeleccion}>Deshacer cambio de foto</Boton>}
            {producto?.imagen_url && !retirar && <Boton variante="fantasma" onClick={() => {
              descartarSeleccion()
              setRetirar(true)
            }}>Retirar foto</Boton>}
          </div>
        </div>
        <Casilla nombre="permite_encargo" checked={valores.permite_encargo} onChange={cambiar}>Se puede pedir por encargo</Casilla>
        <Casilla nombre="activo" checked={valores.activo} onChange={cambiar}>A la venta en el catálogo</Casilla>
      </div>
      <div className="formulario-gestion__acciones">
        <Boton variante="fantasma" onClick={alCancelar}>Cancelar</Boton>
        <Boton tipo="submit" cargando={enviando} disabled={comprobandoFoto}>Guardar</Boton>
      </div>
    </form>
  )
}
