import { useState } from 'react'
import { productoresApi } from '../../api/productoresApi'
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

const VACIA = { nombre: '', municipio: '', departamento: 'Santa Cruz', descripcion: '', activa: true }

/** Comunidades agrícolas: alta y edición. */
export default function ComunidadesPagina() {
  const lista = useCargaApi(() => productoresApi.comunidades(), [])
  const [editando, setEditando] = useState(null)
  const [valores, setValores] = useState(VACIA)
  const [errores, setErrores] = useState({})
  const [mensaje, setMensaje] = useState(null)
  useMensajeTemporal(mensaje, setMensaje)
  const [enviando, setEnviando] = useState(false)

  const abrir = (c) => {
    setEditando(c ?? { id: null })
    setValores(c ? { nombre: c.nombre, municipio: c.municipio, departamento: c.departamento, descripcion: c.descripcion ?? '', activa: c.activa } : VACIA)
    setErrores({})
    setMensaje(null)
  }

  const cambiar = (e) => {
    const { name, type, checked, value } = e.target
    setValores((v) => ({ ...v, [name]: type === 'checkbox' ? checked : value }))
  }

  const guardar = async (e) => {
    e.preventDefault()
    const formularioHtml = e.currentTarget
    const errs = {}
    if (valores.nombre.trim().length < 3) errs.nombre = 'El nombre debe tener al menos 3 caracteres.'
    if (valores.municipio.trim().length < 3) errs.municipio = 'Escribe el municipio.'
    setErrores(errs)
    if (Object.keys(errs).length) { enfocarPrimerError(formularioHtml); return }
    setEnviando(true)
    try {
      const r = editando.id ? await productoresApi.actualizarComunidad(editando.id, valores) : await productoresApi.crearComunidad(valores)
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

  const columnas = [
    { clave: 'nombre', etiqueta: 'Comunidad' },
    { clave: 'ubicacion', etiqueta: 'Municipio', alRender: (c) => `${c.municipio}, ${c.departamento}` },
    { clave: 'productores', etiqueta: 'Productores afiliados' },
    { clave: 'estado', etiqueta: 'Estado', alRender: (c) => <Insignia tono={c.activa ? 'exito' : 'aviso'}>{c.activa ? 'Activa' : 'Inactiva'}</Insignia> },
    {
      clave: 'acciones', etiqueta: 'Acciones', claseCabecera: 'solo-lector',
      alRender: (c) => <Boton variante="secundario" onClick={() => abrir(c)}>Editar</Boton>,
    },
  ]

  return (
    <PaginaListado
        titulo="Comunidades"
        descripcion="Da de alta y edita las comunidades agrícolas afiliadas a FEDER."
        accionPrincipal={!editando && <Boton onClick={() => abrir(null)}>Nueva comunidad</Boton>}
        cargando={!editando && lista.cargando && !lista.datos}
        actualizando={lista.actualizando}
      >
      {lista.error && <Alerta tipo="error">{lista.error} <Boton onClick={lista.recargar} variante="fantasma">Reintentar</Boton></Alerta>}
      {mensaje && <Alerta tipo={mensaje.tipo}>{mensaje.texto}</Alerta>}

      {editando && (
        <form className="formulario-gestion" onSubmit={guardar} noValidate>
          <h2>{editando.id ? `Editar ${editando.nombre}` : 'Nueva comunidad'}</h2>
          <div className="formulario-gestion__rejilla">
            <CampoTexto etiqueta="Nombre" nombre="nombre" obligatorio maxLength={120} value={valores.nombre} onChange={cambiar} error={errores.nombre} />
            <CampoTexto etiqueta="Municipio" nombre="municipio" obligatorio maxLength={80} value={valores.municipio} onChange={cambiar} error={errores.municipio} />
            <CampoTexto etiqueta="Departamento" nombre="departamento" maxLength={40} value={valores.departamento} onChange={cambiar} error={errores.departamento} />
            <div className="formulario-gestion__ancho">
              <CampoTexto etiqueta="Descripción" opcional nombre="descripcion" maxLength={1000} value={valores.descripcion} onChange={cambiar} error={errores.descripcion} />
            </div>
            <Casilla nombre="activa" checked={valores.activa} onChange={cambiar}>Activa</Casilla>
          </div>
          <div className="formulario-gestion__acciones">
            <Boton variante="fantasma" onClick={() => setEditando(null)}>Cancelar</Boton>
            <Boton tipo="submit" cargando={enviando}>Guardar</Boton>
          </div>
        </form>
      )}

      <TablaDatos columnas={columnas} filas={lista.datos?.comunidades ?? []} />
    </PaginaListado>
  )
}
