import { useState } from 'react'
import { catalogoGestionApi } from '../../api/catalogoGestionApi'
import Alerta from '../../components/ui/Alerta'
import Boton from '../../components/ui/Boton'
import CampoTexto from '../../components/ui/CampoTexto'
import Casilla from '../../components/ui/Casilla'
import Insignia from '../../components/ui/Insignia'
import PaginaListado from '../../components/plantillas/PaginaListado'
import { enfocarPrimerError } from '../../utils/validaciones'
import TablaDatos from '../../components/plantillas/TablaDatos'
import { useCargaApi } from '../../hooks/useCargaApi'
import { useMensajeTemporal } from '../../hooks/useMensajeTemporal'
import '../../components/gestion/FormularioGestion.css'

const VACIA = { nombre: '', descripcion: '', activa: true }

/** Categorías del catálogo: alta, edición y retiro. */
export default function CategoriasPagina() {
  const lista = useCargaApi(() => catalogoGestionApi.categorias(), [])
  const [editando, setEditando] = useState(null)
  const [valores, setValores] = useState(VACIA)
  const [errores, setErrores] = useState({})
  const [mensaje, setMensaje] = useState(null)
  const [enviando, setEnviando] = useState(false)
  useMensajeTemporal(mensaje, setMensaje)

  const abrir = (categoria) => {
    setEditando(categoria ?? { id: null })
    setValores(categoria ? { nombre: categoria.nombre, descripcion: categoria.descripcion ?? '', activa: categoria.activa } : VACIA)
    setErrores({})
    setMensaje(null)
  }

  const guardar = async (e) => {
    e.preventDefault()
    const formularioHtml = e.currentTarget
    if (valores.nombre.trim().length < 3) {
      setErrores({ nombre: 'El nombre debe tener al menos 3 caracteres.' })
      enfocarPrimerError(formularioHtml)
      return
    }
    setEnviando(true)
    try {
      const r = editando.id ? await catalogoGestionApi.actualizarCategoria(editando.id, valores) : await catalogoGestionApi.crearCategoria(valores)
      setMensaje({ tipo: 'exito', texto: r.message })
      setEditando(null)
      lista.recargar()
    } catch (falla) {
      setErrores(Object.fromEntries(Object.entries(falla.errores || {}).map(([c, m]) => [c, m[0]])))
      setMensaje({ tipo: 'error', texto: falla.mensaje })
      enfocarPrimerError(formularioHtml)
    } finally {
      setEnviando(false)
    }
  }

  const cambiar = (e) => {
    const { name, type, checked, value } = e.target
    setValores((v) => ({ ...v, [name]: type === 'checkbox' ? checked : value }))
  }

  const columnas = [
    { clave: 'nombre', etiqueta: 'Nombre' },
    { clave: 'descripcion', etiqueta: 'Descripción', alRender: (c) => c.descripcion ?? '—' },
    { clave: 'productos', etiqueta: 'Productos' },
    { clave: 'estado', etiqueta: 'Estado', alRender: (c) => <Insignia tono={c.activa ? 'exito' : 'aviso'}>{c.activa ? 'Visible' : 'Oculta'}</Insignia> },
    {
      clave: 'acciones', etiqueta: 'Acciones', claseCabecera: 'solo-lector',
      alRender: (c) => <Boton variante="secundario" onClick={() => abrir(c)}>Editar</Boton>,
    },
  ]

  return (
    <PaginaListado
        titulo="Categorías"
        descripcion="Administra las familias del catálogo: alta, edición y retiro."
        accionPrincipal={!editando && <Boton onClick={() => abrir(null)}>Nueva categoría</Boton>}
        cargando={!editando && lista.cargando && !lista.datos}
        actualizando={lista.actualizando}
      >
      {lista.error && <Alerta tipo="error">{lista.error} <Boton onClick={lista.recargar} variante="fantasma">Reintentar</Boton></Alerta>}

      {mensaje && <Alerta tipo={mensaje.tipo}>{mensaje.texto}</Alerta>}

      {editando && (
        <form className="formulario-gestion" onSubmit={guardar} noValidate>
          <h2>{editando.id ? `Editar ${editando.nombre}` : 'Nueva categoría'}</h2>
          <div className="formulario-gestion__rejilla">
            <CampoTexto etiqueta="Nombre" nombre="nombre" obligatorio maxLength={60} value={valores.nombre} onChange={cambiar} error={errores.nombre} />
            <CampoTexto etiqueta="Descripción" opcional nombre="descripcion" maxLength={500} value={valores.descripcion} onChange={cambiar} error={errores.descripcion} />
            <Casilla nombre="activa" checked={valores.activa} onChange={cambiar}>Visible en el catálogo</Casilla>
          </div>
          <div className="formulario-gestion__acciones">
            <Boton variante="fantasma" onClick={() => setEditando(null)}>Cancelar</Boton>
            <Boton tipo="submit" cargando={enviando}>Guardar</Boton>
          </div>
        </form>
      )}

      <TablaDatos columnas={columnas} filas={lista.datos?.categorias ?? []} />
    </PaginaListado>
  )
}
