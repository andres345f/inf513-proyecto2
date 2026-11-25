import { useMemo, useState } from 'react'
import { Lock } from 'lucide-react'
import { permisosApi } from '../../api/adminApi'
import Alerta from '../../components/ui/Alerta'
import Boton from '../../components/ui/Boton'
import Cargando from '../../components/ui/Cargando'
import EncabezadoPagina from '../../components/plantillas/EncabezadoPagina'
import { useCargaApi } from '../../hooks/useCargaApi'
import { useMensajeTemporal } from '../../hooks/useMensajeTemporal'
import { nombreRol } from '../../utils/formato'
import './Permisos.css'

/**
 * Matriz de permisos por rol (RBAC). Cada permiso habilita entradas del menú lateral,
 * así que marcar o desmarcar un permiso también muestra u oculta esas opciones del menú.
 */
export default function PermisosPagina() {
  const matriz = useCargaApi(() => permisosApi.matriz(), [])
  const [rol, setRol] = useState('CLIENTE')
  const [borrador, setBorrador] = useState(null)
  const [guardando, setGuardando] = useState(false)
  const [mensaje, setMensaje] = useState(null)
  useMensajeTemporal(mensaje, setMensaje)

  const datos = matriz.datos
  const guardados = useMemo(() => datos?.roles.find((r) => r.codigo === rol)?.permisos ?? [], [datos, rol])
  const marcados = borrador ?? guardados
  const imprescindibles = datos?.imprescindibles[rol] ?? []
  const hayCambios = borrador !== null && (borrador.length !== guardados.length || borrador.some((p) => !guardados.includes(p)))

  const grupos = useMemo(() => {
    const porGrupo = new Map()
    datos?.permisos.forEach((p) => porGrupo.set(p.grupo, [...(porGrupo.get(p.grupo) ?? []), p]))
    return [...porGrupo.entries()]
  }, [datos])

  const elegirRol = (codigo) => {
    setRol(codigo)
    setBorrador(null)
    setMensaje(null)
  }

  const alternar = (clave) => {
    setBorrador(marcados.includes(clave) ? marcados.filter((p) => p !== clave) : [...marcados, clave])
  }

  const guardar = async () => {
    setGuardando(true)
    setMensaje(null)
    try {
      const respuesta = await permisosApi.guardar(rol, marcados)
      matriz.reemplazar(respuesta)
      setBorrador(null)
      setMensaje({ tipo: 'exito', texto: respuesta.mensaje })
    } catch (falla) {
      setMensaje({ tipo: 'error', texto: Object.values(falla.errores || {})[0]?.[0] || falla.mensaje })
    } finally {
      setGuardando(false)
    }
  }

  if (matriz.cargando) return <Cargando texto="Cargando permisos…" />
  if (matriz.error) return <Alerta tipo="error">{matriz.error}</Alerta>

  return (
    <div className="permisos">
      <EncabezadoPagina
        titulo="Permisos y menú"
        descripcion="Elige un rol y marca las operaciones que puede realizar. Las casillas bloqueadas indican los límites de ese rol."
      />

      <div className="permisos__roles" role="group" aria-label="Rol a editar">
        {datos.roles.map((r) => (
          <button key={r.codigo} type="button" className="permisos__rol" aria-pressed={r.codigo === rol} onClick={() => elegirRol(r.codigo)}>
            {nombreRol(r.codigo)} <span>{r.permisos.length}</span>
          </button>
        ))}
      </div>

      <div className="permisos__grupos">
        {grupos.map(([grupo, permisos]) => (
          <fieldset key={grupo} className="permisos__grupo">
            <legend>{grupo}</legend>
            {permisos.map((p) => {
              const rolesPermitidos = datos.reservados?.[p.clave]
              const reservado = rolesPermitidos && !rolesPermitidos.includes(rol)
              const bloqueado = reservado || imprescindibles.includes(p.clave)
              return (
                <label key={p.clave} className="permisos__opcion">
                  <input type="checkbox" checked={marcados.includes(p.clave)} disabled={bloqueado} onChange={() => alternar(p.clave)} />
                  <span className="permisos__texto">
                    <strong>{p.entradas.join(', ')}</strong>
                    <small>{p.clave}</small>
                  </span>
                  {bloqueado && <Lock size={16} aria-label={reservado ? `Reservado para ${rolesPermitidos.map(nombreRol).join(', ')}` : 'Obligatorio para este rol'} />}
                </label>
              )
            })}
          </fieldset>
        ))}
      </div>

      <div className="permisos__acciones">
        {mensaje && <div className="permisos__mensaje"><Alerta tipo={mensaje.tipo}>{mensaje.texto}</Alerta></div>}
        <span aria-live="polite">{hayCambios ? 'Tienes cambios sin guardar.' : 'Sin cambios.'}</span>
        <Boton variante="fantasma" disabled={!hayCambios || guardando} onClick={() => setBorrador(null)}>Descartar</Boton>
        <Boton disabled={!hayCambios} cargando={guardando} onClick={guardar}>Guardar permisos de {nombreRol(rol)}</Boton>
      </div>
    </div>
  )
}
